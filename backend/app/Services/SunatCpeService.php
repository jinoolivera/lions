<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleDocument;
use App\Models\SunatSetting;
use App\Models\User;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use ZipArchive;

class SunatCpeService
{
    private const UBL = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    public function issueSale(Sale $sale, string $documentType, User $user): SaleDocument
    {
        $settings = SunatSetting::query()->whereKey(1)->firstOrFail();
        abort_unless($settings->billing_mode === 'integrated', 409, 'Activa el modo de emisión integrada en Facturación Perú.');
        abort_unless($settings->sending_enabled, 409, 'La emisión está desactivada en la configuración SUNAT.');
        abort_unless($settings->environment === 'beta', 409, 'La emisión directa está limitada al ambiente Beta hasta completar homologación.');
        abort_if(in_array($sale->status, ['cancelled', 'returned'], true), 409, 'No se puede emitir por una venta anulada o devuelta.');
        abort_unless($settings->ruc && $settings->legal_name && $settings->fiscal_address && $settings->ubigeo, 422, 'Completa RUC, razón social, domicilio fiscal y ubigeo en Facturación Perú.');
        abort_if($settings->environment === 'production' && (! $settings->sol_user || ! $settings->sol_password || ! $settings->certificate_path), 422, 'Producción requiere usuario SOL secundario y certificado digital.');
        abort_if($settings->environment === 'beta' && ! $settings->ruc, 422, 'Beta requiere el RUC emisor para autenticar y nombrar el XML.');
        abort_unless(in_array($documentType, ['invoice', 'receipt'], true), 422, 'Tipo de comprobante no admitido.');

        $sale->loadMissing(['lines', 'customer']);
        if ($documentType === 'invoice' && ($sale->customer?->tax_id_type !== 'ruc' || ! preg_match('/^\d{11}$/', (string) $sale->customer?->tax_id))) {
            throw ValidationException::withMessages(['customer' => 'La factura requiere un cliente registrado con RUC de 11 dígitos.']);
        }
        if (! $sale->lines->count()) {
            throw ValidationException::withMessages(['sale' => 'La venta no contiene líneas para emitir.']);
        }
        if ($sale->lines->contains(fn ($line) => (int) $line->discount_cents > 0)) {
            throw ValidationException::withMessages(['sale' => 'Beta todavía no permite emitir ventas con descuentos; primero completa la configuración fiscal del descuento.']);
        }
        if ((int) ($sale->returned_cents ?? 0) > 0) {
            throw ValidationException::withMessages(['sale' => 'Esta venta tiene una devolución; requiere una nota de crédito y no puede emitirse como factura original.']);
        }
        if ($sale->documents()->where('environment', $settings->environment)->exists()) {
            throw ValidationException::withMessages(['sale' => 'Esta venta ya tiene un comprobante para el ambiente seleccionado.']);
        }

        $counterColumn = 'next_'.($documentType === 'invoice' ? 'invoice' : 'receipt').'_'.$settings->environment;
        $document = DB::transaction(function () use ($settings, $counterColumn, $sale, $documentType, $user): SaleDocument {
            $locked = SunatSetting::query()->whereKey($settings->id)->lockForUpdate()->firstOrFail();
            $number = (string) $locked->{$counterColumn};
            $locked->increment($counterColumn);
            $series = $documentType === 'invoice' ? $locked->invoice_series : $locked->receipt_series;

            return $sale->documents()->create([
                'user_id' => $user->id,
                'document_type' => $documentType,
                'series' => $series,
                'number' => $number,
                'issue_date' => now()->toDateString(),
                'total_cents' => $sale->total_cents,
                'issued_via' => 'see_contributor',
                'environment' => $locked->environment,
                'status' => 'sending',
                'sent_at' => now(),
            ]);
        }, 3);

        try {
            $certificate = $this->certificateMaterial($settings);
            $xml = $this->buildInvoiceXml($sale, $document, $settings, $certificate);
            $xmlPath = 'sunat/documents/'.$document->environment.'/'.$document->id.'.xml.enc';
            Storage::disk('local')->put($xmlPath, Crypt::encryptString($xml));
            $document->xml_path = $xmlPath;
            $zip = $this->zipDocument($settings->ruc, $document, $xml);
            $response = $this->sendBill($settings, $document, $zip);
            $cdr = $this->extractCdr($response);
            $cdrPath = 'sunat/cdr/'.$document->environment.'/'.$document->id.'.xml.enc';
            Storage::disk('local')->put($cdrPath, Crypt::encryptString($cdr));
            [$code, $message] = $this->readCdrResult($cdr);
            $document->cdr_path = $cdrPath;
            $document->sunat_response_code = $code;
            $document->sunat_message = $message;
            $document->status = $code === '0' ? 'accepted' : 'rejected';
            $document->save();
        } catch (\Throwable $exception) {
            report($exception);
            $document->status = 'communication_error';
            $document->sunat_message = mb_substr($exception->getMessage(), 0, 2000);
            $document->save();
            throw ValidationException::withMessages(['sunat' => 'SUNAT no confirmó el comprobante. Número reservado: '.$document->series.'-'.$document->number.'. '.$exception->getMessage()]);
        }

        return $document->fresh();
    }

    public function download(SaleDocument $document, string $kind): string
    {
        $path = $kind === 'cdr' ? $document->cdr_path : $document->xml_path;
        abort_unless($path, 404, 'El archivo todavía no está disponible.');

        return Crypt::decryptString(Storage::disk('local')->get($path));
    }

    private function buildInvoiceXml(Sale $sale, SaleDocument $document, SunatSetting $settings, array $certificate): string
    {
        $doc = new DOMDocument('1.0', 'ISO-8859-1');
        $doc->formatOutput = true;
        $root = $doc->createElementNS(self::UBL, 'Invoice');
        $doc->appendChild($root);
        foreach (['cac' => self::CAC, 'cbc' => self::CBC, 'ext' => self::EXT, 'ds' => 'http://www.w3.org/2000/09/xmldsig#'] as $prefix => $uri) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:'.$prefix, $uri);
        }
        $exts = $root->appendChild($doc->createElementNS(self::EXT, 'ext:UBLExtensions'));
        $ext = $exts->appendChild($doc->createElementNS(self::EXT, 'ext:UBLExtension'));
        $signatureSlot = $ext->appendChild($doc->createElementNS(self::EXT, 'ext:ExtensionContent'));

        $this->el($doc, $root, self::CBC, 'cbc:UBLVersionID', '2.1');
        $this->el($doc, $root, self::CBC, 'cbc:CustomizationID', '2.0');
        $profile = $this->el($doc, $root, self::CBC, 'cbc:ProfileID', '0101');
        $profile->setAttribute('schemeName', 'SUNAT:Identificador de Tipo de Operación');
        $profile->setAttribute('schemeAgencyName', 'PE:SUNAT');
        $profile->setAttribute('schemeURI', 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo17');
        $this->el($doc, $root, self::CBC, 'cbc:ID', $document->series.'-'.$document->number);
        $this->el($doc, $root, self::CBC, 'cbc:IssueDate', $document->issue_date->format('Y-m-d'));
        $this->el($doc, $root, self::CBC, 'cbc:IssueTime', now()->format('H:i:s'));
        $type = $this->el($doc, $root, self::CBC, 'cbc:InvoiceTypeCode', $document->document_type === 'invoice' ? '01' : '03');
        $type->setAttribute('listAgencyName', 'PE:SUNAT');
        $type->setAttribute('listName', 'SUNAT:Identificador de Tipo de Documento');
        $type->setAttribute('listURI', 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01');
        $note = $this->el($doc, $root, self::CBC, 'cbc:Note', $this->amountInWords($sale->total_cents));
        $note->setAttribute('languageLocaleID', '1000');
        $currency = $this->el($doc, $root, self::CBC, 'cbc:DocumentCurrencyCode', 'PEN');
        $currency->setAttribute('listID', 'ISO 4217 Alpha');
        $currency->setAttribute('listName', 'Currency');
        $currency->setAttribute('listAgencyName', 'United Nations Economic Commission for Europe');
        $this->el($doc, $root, self::CBC, 'cbc:LineCountNumeric', (string) $sale->lines->count());
        $this->appendUblSignatureParty($doc, $root, $settings);
        $this->appendSupplier($doc, $root, $settings);
        $this->appendCustomer($doc, $root, $sale, $document->document_type);

        $rate = (float) ($settings->igv_rate ?: 18);
        $taxTotal = 0;
        $baseTotal = 0;
        $lineData = [];
        foreach ($sale->lines as $line) {
            $gross = (int) $line->line_total_cents;
            $base = (int) round($gross * 100 / (100 + $rate));
            $tax = $gross - $base;
            $taxTotal += $tax;
            $baseTotal += $base;
            $lineData[] = [$line, $gross, $base, $tax];
        }
        $taxNode = $root->appendChild($doc->createElementNS(self::CAC, 'cac:TaxTotal'));
        $this->money($doc, $taxNode, 'cac:TaxAmount', $taxTotal);
        $subtotal = $taxNode->appendChild($doc->createElementNS(self::CAC, 'cac:TaxSubtotal'));
        $this->money($doc, $subtotal, 'cac:TaxableAmount', $baseTotal);
        $this->money($doc, $subtotal, 'cac:TaxAmount', $taxTotal);
        $category = $subtotal->appendChild($doc->createElementNS(self::CAC, 'cac:TaxCategory'));
        $taxCategoryId = $this->el($doc, $category, self::CBC, 'cbc:ID', 'S');
        $taxCategoryId->setAttribute('schemeID', 'UN/ECE 5305');
        $taxCategoryId->setAttribute('schemeName', 'Tax Category Identifier');
        $taxCategoryId->setAttribute('schemeAgencyName', 'United Nations Economic Commission for Europe');
        $this->el($doc, $category, self::CBC, 'cbc:Percent', number_format($rate, 2, '.', ''));
        $reason = $this->el($doc, $category, self::CBC, 'cbc:TaxExemptionReasonCode', '10');
        $reason->setAttribute('listAgencyName', 'PE:SUNAT');
        $reason->setAttribute('listName', 'SUNAT:Codigo de Tipo de Afectación del IGV');
        $reason->setAttribute('listURI', 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo07');
        $this->taxScheme($doc, $category);

        $legal = $root->appendChild($doc->createElementNS(self::CAC, 'cac:LegalMonetaryTotal'));
        $this->money($doc, $legal, 'cac:LineExtensionAmount', $baseTotal);
        $this->money($doc, $legal, 'cac:TaxInclusiveAmount', $sale->total_cents);
        $this->money($doc, $legal, 'cac:PayableAmount', $sale->total_cents);

        foreach ($lineData as $index => [$line, $gross, $base, $tax]) {
            $node = $root->appendChild($doc->createElementNS(self::CAC, 'cac:InvoiceLine'));
            $this->el($doc, $node, self::CBC, 'cbc:ID', (string) ($index + 1));
            $qty = $this->el($doc, $node, self::CBC, 'cbc:InvoicedQuantity', $this->decimal($line->quantity));
            $qty->setAttribute('unitCode', 'NIU');
            $this->money($doc, $node, 'cbc:LineExtensionAmount', $base);
            $pricing = $node->appendChild($doc->createElementNS(self::CAC, 'cac:PricingReference'));
            $alternative = $pricing->appendChild($doc->createElementNS(self::CAC, 'cac:AlternativeConditionPrice'));
            $this->money($doc, $alternative, 'cac:PriceAmount', (int) $line->unit_price_cents);
            $priceType = $this->el($doc, $alternative, self::CBC, 'cbc:PriceTypeCode', '01');
            $priceType->setAttribute('listName', 'SUNAT:Tipo de Precio');
            $priceType->setAttribute('listAgencyName', 'PE:SUNAT');
            $lineTax = $node->appendChild($doc->createElementNS(self::CAC, 'cac:TaxTotal'));
            $this->money($doc, $lineTax, 'cac:TaxAmount', $tax);
            $lineSub = $lineTax->appendChild($doc->createElementNS(self::CAC, 'cac:TaxSubtotal'));
            $this->money($doc, $lineSub, 'cac:TaxableAmount', $base);
            $this->money($doc, $lineSub, 'cac:TaxAmount', $tax);
            $lineCategory = $lineSub->appendChild($doc->createElementNS(self::CAC, 'cac:TaxCategory'));
            $lineCategoryId = $this->el($doc, $lineCategory, self::CBC, 'cbc:ID', 'S');
            $lineCategoryId->setAttribute('schemeID', 'UN/ECE 5305');
            $lineCategoryId->setAttribute('schemeName', 'Tax Category Identifier');
            $lineCategoryId->setAttribute('schemeAgencyName', 'United Nations Economic Commission for Europe');
            $this->el($doc, $lineCategory, self::CBC, 'cbc:Percent', number_format($rate, 2, '.', ''));
            $lineReason = $this->el($doc, $lineCategory, self::CBC, 'cbc:TaxExemptionReasonCode', '10');
            $lineReason->setAttribute('listAgencyName', 'PE:SUNAT');
            $lineReason->setAttribute('listName', 'SUNAT:Codigo de Tipo de Afectación del IGV');
            $lineReason->setAttribute('listURI', 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo07');
            $this->taxScheme($doc, $lineCategory);
            $item = $node->appendChild($doc->createElementNS(self::CAC, 'cac:Item'));
            $this->el($doc, $item, self::CBC, 'cbc:Description', $line->product_name_snapshot);
            $sellerId = $item->appendChild($doc->createElementNS(self::CAC, 'cac:SellersItemIdentification'));
            $this->el($doc, $sellerId, self::CBC, 'cbc:ID', $line->product?->sku ?? (string) $line->product_id);
            $price = $node->appendChild($doc->createElementNS(self::CAC, 'cac:Price'));
            $unitBase = (int) round(((int) $line->unit_price_cents) * 100 / (100 + $rate));
            $this->money($doc, $price, 'cac:PriceAmount', $unitBase);
        }

        $signature = new XMLSecurityDSig('SignatureSP');
        $signature->setCanonicalMethod(XMLSecurityDSig::C14N);
        $signature->addReference($doc, XMLSecurityDSig::SHA1, ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'], ['force_uri' => true]);
        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'private']);
        $key->loadKey($certificate['private_key']);
        $signature->sign($key);
        $signature->add509Cert($certificate['certificate']);
        $signature->appendSignature($signatureSlot);

        $xml = $doc->saveXML();
        if (! is_string($xml)) {
            throw new \RuntimeException('No se pudo construir el XML UBL.');
        }

        return $xml;
    }

    private function appendUblSignatureParty(DOMDocument $doc, DOMElement $root, SunatSetting $settings): void
    {
        $signature = $root->appendChild($doc->createElementNS(self::CAC, 'cac:Signature'));
        $this->el($doc, $signature, self::CBC, 'cbc:ID', 'SIGN'.$settings->ruc);
        $party = $signature->appendChild($doc->createElementNS(self::CAC, 'cac:SignatoryParty'));
        $identification = $party->appendChild($doc->createElementNS(self::CAC, 'cac:PartyIdentification'));
        $this->el($doc, $identification, self::CBC, 'cbc:ID', $settings->ruc);
        $legal = $party->appendChild($doc->createElementNS(self::CAC, 'cac:PartyLegalEntity'));
        $this->el($doc, $legal, self::CBC, 'cbc:RegistrationName', $settings->legal_name);
        $attachment = $signature->appendChild($doc->createElementNS(self::CAC, 'cac:DigitalSignatureAttachment'));
        $external = $attachment->appendChild($doc->createElementNS(self::CAC, 'cac:ExternalReference'));
        $this->el($doc, $external, self::CBC, 'cbc:URI', '#SignatureSP');
    }

    private function appendSupplier(DOMDocument $doc, DOMElement $root, SunatSetting $settings): void
    {
        $supplier = $root->appendChild($doc->createElementNS(self::CAC, 'cac:AccountingSupplierParty'));
        $party = $supplier->appendChild($doc->createElementNS(self::CAC, 'cac:Party'));
        $identification = $party->appendChild($doc->createElementNS(self::CAC, 'cac:PartyIdentification'));
        $partyId = $this->el($doc, $identification, self::CBC, 'cbc:ID', $settings->ruc);
        $partyId->setAttribute('schemeID', '6');
        $partyId->setAttribute('schemeName', 'Documento de Identidad');
        $partyId->setAttribute('schemeAgencyName', 'PE:SUNAT');
        $partyId->setAttribute('schemeURI', 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06');
        if ($settings->trade_name) {
            $partyName = $party->appendChild($doc->createElementNS(self::CAC, 'cac:PartyName'));
            $this->el($doc, $partyName, self::CBC, 'cbc:Name', $settings->trade_name);
        }
        $taxScheme = $party->appendChild($doc->createElementNS(self::CAC, 'cac:PartyTaxScheme'));
        $this->el($doc, $taxScheme, self::CBC, 'cbc:RegistrationName', $settings->legal_name);
        $ruc = $this->el($doc, $taxScheme, self::CBC, 'cbc:CompanyID', $settings->ruc);
        $ruc->setAttribute('schemeID', '6');
        $ruc->setAttribute('schemeName', 'SUNAT:Identificador de Documento de Identidad');
        $ruc->setAttribute('schemeAgencyName', 'PE:SUNAT');
        $ruc->setAttribute('schemeURI', 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06');
        $taxCode = $taxScheme->appendChild($doc->createElementNS(self::CAC, 'cac:TaxScheme'));
        $this->el($doc, $taxCode, self::CBC, 'cbc:ID', '-');

        $legalEntity = $party->appendChild($doc->createElementNS(self::CAC, 'cac:PartyLegalEntity'));
        $this->el($doc, $legalEntity, self::CBC, 'cbc:RegistrationName', $settings->legal_name);
        $address = $legalEntity->appendChild($doc->createElementNS(self::CAC, 'cac:RegistrationAddress'));
        $ubigeo = $this->el($doc, $address, self::CBC, 'cbc:ID', $settings->ubigeo);
        $ubigeo->setAttribute('schemeName', 'Ubigeos');
        $ubigeo->setAttribute('schemeAgencyName', 'PE:INEI');
        $line = $address->appendChild($doc->createElementNS(self::CAC, 'cac:AddressLine'));
        $this->el($doc, $line, self::CBC, 'cbc:Line', $settings->fiscal_address);
        $country = $address->appendChild($doc->createElementNS(self::CAC, 'cac:Country'));
        $countryCode = $this->el($doc, $country, self::CBC, 'cbc:IdentificationCode', 'PE');
        $countryCode->setAttribute('listID', 'ISO 3166-1');
        $countryCode->setAttribute('listName', 'Country');
        $countryCode->setAttribute('listAgencyName', 'United Nations Economic Commission for Europe');
    }

    private function appendCustomer(DOMDocument $doc, DOMElement $root, Sale $sale, string $documentType): void
    {
        $customer = $root->appendChild($doc->createElementNS(self::CAC, 'cac:AccountingCustomerParty'));
        $party = $customer->appendChild($doc->createElementNS(self::CAC, 'cac:Party'));
        $record = $sale->customer;
        $name = $record?->name ?: ($sale->customer_name ?: 'CLIENTE VARIOS');
        if ($record?->tax_id_type === 'ruc' && preg_match('/^\\d{11}$/', (string) $record->tax_id)) {
            $idCode = '6';
            $idNumber = $record->tax_id;
        } elseif ($record?->tax_id_type === 'dni' && preg_match('/^\\d{8}$/', (string) $record->tax_id)) {
            $idCode = '1';
            $idNumber = $record->tax_id;
        } elseif ($documentType === 'invoice') {
            throw ValidationException::withMessages(['customer' => 'Registra el RUC del cliente antes de emitir factura.']);
        } elseif ($record?->tax_id) {
            $idCode = '0';
            $idNumber = $record->tax_id;
        } else {
            $idCode = '0';
            $idNumber = '0';
        }
        $identification = $party->appendChild($doc->createElementNS(self::CAC, 'cac:PartyIdentification'));
        $partyId = $this->el($doc, $identification, self::CBC, 'cbc:ID', $idNumber);
        $partyId->setAttribute('schemeID', $idCode);
        $partyId->setAttribute('schemeName', 'Documento de Identidad');
        $partyId->setAttribute('schemeAgencyName', 'PE:SUNAT');
        $partyId->setAttribute('schemeURI', 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06');
        $taxScheme = $party->appendChild($doc->createElementNS(self::CAC, 'cac:PartyTaxScheme'));
        $this->el($doc, $taxScheme, self::CBC, 'cbc:RegistrationName', $name);
        $id = $this->el($doc, $taxScheme, self::CBC, 'cbc:CompanyID', $idNumber);
        $id->setAttribute('schemeID', $idCode);
        $id->setAttribute('schemeName', 'SUNAT:Identificador de Documento de Identidad');
        $id->setAttribute('schemeAgencyName', 'PE:SUNAT');
        $id->setAttribute('schemeURI', 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06');
        $taxCode = $taxScheme->appendChild($doc->createElementNS(self::CAC, 'cac:TaxScheme'));
        $this->el($doc, $taxCode, self::CBC, 'cbc:ID', '-');
        $legalEntity = $party->appendChild($doc->createElementNS(self::CAC, 'cac:PartyLegalEntity'));
        $this->el($doc, $legalEntity, self::CBC, 'cbc:RegistrationName', $name);
    }

    private function certificateMaterial(SunatSetting $settings): array
    {
        if ($settings->certificate_path) {
            $encrypted = Storage::disk('local')->get($settings->certificate_path);
            $p12 = Crypt::decryptString($encrypted);
            $parts = [];
            if (! openssl_pkcs12_read($p12, $parts, (string) $settings->certificate_password) || empty($parts['pkey']) || empty($parts['cert'])) {
                throw new \RuntimeException('No se pudo abrir el certificado guardado. Verifica su contraseña.');
            }

            return ['private_key' => $parts['pkey'], 'certificate' => $parts['cert']];
        }
        if ($settings->environment !== 'beta') {
            throw new \RuntimeException('Producción necesita un certificado digital P12/PFX.');
        }

        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if (! $private) {
            throw new \RuntimeException('No se pudo crear la clave temporal para pruebas Beta.');
        }
        $csr = openssl_csr_new(['commonName' => $settings->legal_name, 'organizationName' => $settings->legal_name, 'organizationalUnitName' => $settings->ruc, 'countryName' => 'PE'], $private, ['digest_alg' => 'sha256']);
        $certificate = $csr ? openssl_csr_sign($csr, null, $private, 30, ['digest_alg' => 'sha256']) : false;
        $privatePem = $certificatePem = '';
        if (! $certificate || ! openssl_pkey_export($private, $privatePem) || ! openssl_x509_export($certificate, $certificatePem)) {
            throw new \RuntimeException('No se pudo generar el certificado temporal de pruebas Beta.');
        }

        return ['private_key' => $privatePem, 'certificate' => $certificatePem];
    }

    private function zipDocument(string $ruc, SaleDocument $document, string $xml): string
    {
        $type = $document->document_type === 'invoice' ? '01' : '03';
        $filename = strtoupper($ruc.'-'.$type.'-'.$document->series.'-'.$document->number);
        $path = tempnam(sys_get_temp_dir(), 'sunat-');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el ZIP SUNAT.');
        }
        $zip->addFromString($filename.'.xml', $xml);
        $zip->close();
        $contents = file_get_contents($path);
        @unlink($path);
        if (! is_string($contents)) {
            throw new \RuntimeException('No se pudo leer el ZIP SUNAT.');
        }

        return $contents;
    }

    private function sendBill(SunatSetting $settings, SaleDocument $document, string $zip): string
    {
        $type = $document->document_type === 'invoice' ? '01' : '03';
        $file = strtoupper($settings->ruc.'-'.$type.'-'.$document->series.'-'.$document->number.'.ZIP');
        $username = $settings->environment === 'beta' ? $settings->ruc.'MODDATOS' : $settings->ruc.$settings->sol_user;
        $password = $settings->environment === 'beta' ? 'MODDATOS' : $settings->sol_password;
        $url = $settings->environment === 'beta'
            ? 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService'
            : 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService';
        $payload = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"><soapenv:Header><wsse:Security><wsse:UsernameToken><wsse:Username>'.htmlspecialchars($username, ENT_XML1).'</wsse:Username><wsse:Password>'.htmlspecialchars($password, ENT_XML1).'</wsse:Password></wsse:UsernameToken></wsse:Security></soapenv:Header><soapenv:Body><ser:sendBill><fileName>'.$file.'</fileName><contentFile>'.base64_encode($zip).'</contentFile></ser:sendBill></soapenv:Body></soapenv:Envelope>';
        $response = Http::timeout(40)->withHeaders(['SOAPAction' => 'urn:sendBill'])->withBody($payload, 'text/xml; charset=utf-8')->post($url);
        $body = $response->body();
        if (! $response->successful()) {
            $fault = $this->soapFault($body) ?: 'SUNAT respondió HTTP '.$response->status().'.';
            throw new \RuntimeException($fault);
        }
        $xml = new DOMDocument;
        if (! $xml->loadXML($body, LIBXML_NONET)) {
            throw new \RuntimeException('La respuesta de SUNAT no contiene XML SOAP válido.');
        }
        $xpath = new \DOMXPath($xml);
        $fault = $xpath->query('//*[local-name()="Fault"]/*[local-name()="faultstring"]')->item(0)?->textContent;
        if ($fault) {
            throw new \RuntimeException($fault);
        }
        $node = $xpath->query('//*[local-name()="sendBillResponse"]/*[local-name()="applicationResponse"] | //*[local-name()="sendBillResponse"]/*[local-name()="document"]')->item(0);
        if (! $node) {
            throw new \RuntimeException('SUNAT no devolvió el archivo CDR.');
        }
        $decoded = base64_decode(trim($node->textContent), true);
        if (! is_string($decoded) || $decoded === '') {
            throw new \RuntimeException('El archivo CDR devuelto por SUNAT está vacío o no es base64 válido.');
        }

        return $decoded;
    }

    private function extractCdr(string $zipBytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cdr-');
        file_put_contents($path, $zipBytes);
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            @unlink($path);
            throw new \RuntimeException('SUNAT devolvió una respuesta que no es un ZIP CDR.');
        }
        $xml = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && str_ends_with(strtolower($name), '.xml')) {
                $xml = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
        @unlink($path);
        if (! is_string($xml) || $xml === '') {
            throw new \RuntimeException('El ZIP de respuesta no contiene un CDR XML.');
        }

        return $xml;
    }

    private function readCdrResult(string $cdr): array
    {
        $xml = new DOMDocument;
        if (! $xml->loadXML($cdr, LIBXML_NONET)) {
            throw new \RuntimeException('No se pudo interpretar el XML CDR.');
        }
        $xpath = new \DOMXPath($xml);
        $nodes = $xpath->query('//*[local-name()="DocumentResponse"]/*[local-name()="Response"]/*[local-name()="ResponseCode"]');
        $code = $nodes->item(0)?->textContent;
        $description = $xpath->query('//*[local-name()="DocumentResponse"]/*[local-name()="Response"]/*[local-name()="Description"]')->item(0)?->textContent;
        if (! is_string($code) || $code === '') {
            throw new \RuntimeException('El CDR no incluye un código de respuesta.');
        }

        return [$code, $description ?: ($code === '0' ? 'Aceptado por SUNAT.' : 'SUNAT rechazó el comprobante.')];
    }

    private function soapFault(string $body): ?string
    {
        $xml = new DOMDocument;
        if (! @$xml->loadXML($body, LIBXML_NONET)) {
            return null;
        }

        return (new \DOMXPath($xml))->query('//*[local-name()="Fault"]/*[local-name()="faultstring"]')->item(0)?->textContent;
    }

    private function taxScheme(DOMDocument $doc, DOMElement $category): void
    {
        $scheme = $category->appendChild($doc->createElementNS(self::CAC, 'cac:TaxScheme'));
        $schemeId = $this->el($doc, $scheme, self::CBC, 'cbc:ID', '1000');
        $schemeId->setAttribute('schemeID', 'UN/ECE 5153');
        $schemeId->setAttribute('schemeName', 'Tax Scheme Identifier');
        $schemeId->setAttribute('schemeAgencyName', 'United Nations Economic Commission for Europe');
        $this->el($doc, $scheme, self::CBC, 'cbc:Name', 'IGV');
        $this->el($doc, $scheme, self::CBC, 'cbc:TaxTypeCode', 'VAT');
    }

    private function money(DOMDocument $doc, DOMElement $parent, string $qualifiedName, int $cents): DOMElement
    {
        $namespace = str_starts_with($qualifiedName, 'cbc:') ? self::CBC : self::CAC;
        $node = $this->el($doc, $parent, $namespace, $qualifiedName, number_format($cents / 100, 2, '.', ''));
        $node->setAttribute('currencyID', 'PEN');

        return $node;
    }

    private function el(DOMDocument $doc, DOMElement $parent, string $namespace, string $qualifiedName, string $value): DOMElement
    {
        $node = $doc->createElementNS($namespace, $qualifiedName);
        $node->appendChild($doc->createTextNode($value));
        $parent->appendChild($node);

        return $node;
    }

    private function amountInWords(int $cents): string
    {
        $number = intdiv(max(0, $cents), 100);
        $centsPart = max(0, $cents) % 100;

        return $this->spanishInteger($number).' Y '.str_pad((string) $centsPart, 2, '0', STR_PAD_LEFT).'/100 SOLES';
    }

    private function spanishInteger(int $number): string
    {
        if ($number === 0) {
            return 'CERO';
        }
        $units = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
        $special = [10 => 'DIEZ', 11 => 'ONCE', 12 => 'DOCE', 13 => 'TRECE', 14 => 'CATORCE', 15 => 'QUINCE', 16 => 'DIECISEIS', 17 => 'DIECISIETE', 18 => 'DIECIOCHO', 19 => 'DIECINUEVE', 20 => 'VEINTE', 21 => 'VEINTIUNO', 22 => 'VEINTIDOS', 23 => 'VEINTITRES', 24 => 'VEINTICUATRO', 25 => 'VEINTICINCO', 26 => 'VEINTISEIS', 27 => 'VEINTISIETE', 28 => 'VEINTIOCHO', 29 => 'VEINTINUEVE'];
        $tens = [3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA', 7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA'];
        $hundreds = [1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS', 6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS'];
        $parts = [];
        if ($number >= 1000000) {
            $millions = intdiv($number, 1000000);
            $parts[] = $millions === 1 ? 'UN MILLON' : $this->spanishInteger($millions).' MILLONES';
            $number %= 1000000;
        }
        if ($number >= 1000) {
            $thousands = intdiv($number, 1000);
            $parts[] = $thousands === 1 ? 'MIL' : $this->spanishInteger($thousands).' MIL';
            $number %= 1000;
        }
        if ($number === 100) {
            $parts[] = 'CIEN';
        } elseif ($number >= 100) {
            $parts[] = $hundreds[intdiv($number, 100)];
            $number %= 100;
        }
        if ($number >= 10 && $number <= 29) {
            $parts[] = $special[$number];
        } elseif ($number >= 30) {
            $parts[] = $tens[intdiv($number, 10)].($number % 10 ? ' Y '.$units[$number % 10] : '');
        } elseif ($number > 0) {
            $parts[] = $units[$number];
        }

        return implode(' ', $parts);
    }

    private function decimal(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }
}
