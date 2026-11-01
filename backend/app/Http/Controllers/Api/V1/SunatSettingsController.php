<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SunatSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SunatSettingsController extends Controller
{
    private const ENDPOINTS = [
        'beta' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl',
        'production' => 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService?wsdl',
    ];

    public function show(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $settings = SunatSetting::query()->whereKey(1)->first();

        return response()->json(['data' => $this->present($settings)]);
    }

    public function mode(): JsonResponse
    {
        $settings = SunatSetting::query()->whereKey(1)->first();

        return response()->json(['data' => [
            'billing_mode' => $settings?->billing_mode ?? 'internal',
            'environment' => $settings?->environment ?? 'beta',
            'sending_enabled' => (bool) $settings?->sending_enabled,
            'invoice_series' => $settings?->invoice_series ?? 'F001',
            'receipt_series' => $settings?->receipt_series ?? 'B001',
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'ruc' => ['nullable', 'regex:/^\d{11}$/'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'fiscal_address' => ['nullable', 'string', 'max:240'],
            'ubigeo' => ['nullable', 'regex:/^\d{6}$/'],
            'environment' => ['required', 'in:beta,production'],
            'billing_mode' => ['required', 'in:internal,manual,integrated'],
            'igv_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'sol_user' => ['nullable', 'string', 'max:32'],
            'sol_password' => ['nullable', 'string', 'max:255'],
            'invoice_series' => ['required', 'regex:/^F[A-Z0-9]{3}$/'],
            'receipt_series' => ['required', 'regex:/^B[A-Z0-9]{3}$/'],
            'notification_email' => ['nullable', 'email', 'max:255'],
            'sending_enabled' => ['required', 'boolean'],
            'certificate' => ['nullable', 'file', 'mimes:p12,pfx', 'max:2048'],
            'certificate_password' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = SunatSetting::query()->firstOrNew(['id' => 1]);
        $settings->fill(collect($data)->except(['certificate', 'certificate_password', 'sol_password'])->all());

        if (! empty($data['sol_password'])) {
            $settings->sol_password = $data['sol_password'];
        }

        if ($request->hasFile('certificate')) {
            $password = (string) ($data['certificate_password'] ?? '');
            $contents = file_get_contents($request->file('certificate')->getRealPath());
            $certificate = [];
            if (! is_string($contents) || ! openssl_pkcs12_read($contents, $certificate, $password) || empty($certificate['pkey'])) {
                throw ValidationException::withMessages(['certificate' => 'No se pudo abrir el P12/PFX con esa contraseña o no contiene una clave privada.']);
            }

            $path = 'sunat/certificates/'.Str::uuid().'.enc';
            Storage::disk('local')->put($path, Crypt::encryptString($contents));
            if ($settings->certificate_path) {
                Storage::disk('local')->delete($settings->certificate_path);
            }
            $settings->certificate_path = $path;
            $settings->certificate_name = $request->file('certificate')->getClientOriginalName();
            $settings->certificate_password = $password;
        } elseif (! empty($data['certificate_password'])) {
            if (! $settings->certificate_path) {
                throw ValidationException::withMessages(['certificate_password' => 'Carga primero el certificado digital.']);
            }
            $encryptedContents = Storage::disk('local')->get($settings->certificate_path);
            $contents = Crypt::decryptString($encryptedContents);
            $certificate = [];
            if (! openssl_pkcs12_read($contents, $certificate, $data['certificate_password']) || empty($certificate['pkey'])) {
                throw ValidationException::withMessages(['certificate_password' => 'La contraseña no permite abrir el certificado guardado.']);
            }
            $settings->certificate_password = $data['certificate_password'];
        }

        if ($request->boolean('sending_enabled')) {
            if ($settings->environment === 'production' && $settings->billing_mode === 'integrated') {
                throw ValidationException::withMessages(['sending_enabled' => 'La emisión integrada en Producción está bloqueada hasta completar la homologación de Lions.']);
            }
            $basicIssuerDataMissing = ! $settings->ruc || ! $settings->legal_name || ! $settings->fiscal_address || ! $settings->ubigeo;
            $productionCredentialsMissing = $settings->environment === 'production' && (! $settings->sol_user || ! $settings->sol_password || ! $settings->certificate_path);
            if ($basicIssuerDataMissing || $productionCredentialsMissing) {
                throw ValidationException::withMessages(['sending_enabled' => $settings->environment === 'beta'
                    ? 'Para probar en Beta registra RUC, razón social, domicilio fiscal y ubigeo. El certificado no necesita estar registrado en SUNAT; Beta usará uno temporal para firmar.'
                    : 'Producción requiere RUC, razón social, domicilio fiscal, ubigeo, usuario SOL secundario y certificado digital.']);
            }
        }

        // A change to critical credentials or switching environments always requires explicit re-enabling.
        if ($settings->exists && $settings->isDirty(['environment', 'billing_mode', 'ruc', 'legal_name', 'trade_name', 'fiscal_address', 'ubigeo', 'sol_user', 'sol_password', 'certificate_path'])) {
            $settings->sending_enabled = false;
        }

        $settings->save();

        return response()->json(['data' => $this->present($settings)]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $settings = SunatSetting::query()->whereKey(1)->first();
        $environment = $settings?->environment ?? 'beta';

        try {
            $response = Http::timeout(8)->get(self::ENDPOINTS[$environment]);
            $reachable = $response->successful() && str_contains(strtolower($response->body()), 'definitions');
        } catch (ConnectionException) {
            $reachable = false;
        }

        return response()->json(['data' => [
            'reachable' => $reachable,
            'environment' => $environment,
            'endpoint' => self::ENDPOINTS[$environment],
            'message' => $reachable
                ? 'Servicio WSDL accesible. Esto solo verifica conectividad; no prueba credenciales, certificado ni autorización de emisión.'
                : 'No se pudo confirmar acceso al WSDL SUNAT desde el servidor.',
        ]], $reachable ? 200 : 503);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Solo administración puede configurar la conexión SUNAT.');
    }

    private function present(?SunatSetting $settings): array
    {
        return [
            'configured' => (bool) $settings,
            'ruc' => $settings?->ruc,
            'legal_name' => $settings?->legal_name,
            'trade_name' => $settings?->trade_name,
            'fiscal_address' => $settings?->fiscal_address,
            'ubigeo' => $settings?->ubigeo,
            'environment' => $settings?->environment ?? 'beta',
            'billing_mode' => $settings?->billing_mode ?? 'internal',
            'sol_user' => $settings?->sol_user,
            'has_sol_password' => filled($settings?->sol_password),
            'has_certificate' => filled($settings?->certificate_path),
            'certificate_name' => $settings?->certificate_name,
            'invoice_series' => $settings?->invoice_series ?? 'F001',
            'receipt_series' => $settings?->receipt_series ?? 'B001',
            'notification_email' => $settings?->notification_email,
            'sending_enabled' => (bool) $settings?->sending_enabled,
            'connection_ready' => $settings?->environment === 'beta'
                ? (bool) ($settings?->ruc && $settings?->legal_name && $settings?->fiscal_address && $settings?->ubigeo)
                : (bool) ($settings?->ruc && $settings?->legal_name && $settings?->fiscal_address && $settings?->ubigeo && $settings?->sol_user && $settings?->sol_password && $settings?->certificate_path),
            'igv_rate' => $settings?->igv_rate ?? '18.00',
            'beta_certificate_optional' => true,
        ];
    }
}
