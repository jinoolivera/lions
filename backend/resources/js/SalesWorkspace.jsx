import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';

const money = (cents) => `S/ ${(Number(cents || 0) / 100).toFixed(2)}`;
const errorText = (error) => Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'No se pudo completar la operación.';
const docName = (type) => type === 'invoice' ? 'Factura' : type === 'receipt' ? 'Boleta' : 'Sin elegir';

function SaleTicketDetail({ sale, warehouse, onTicket }) {
    return <div className="panel sales-workspace-sale-card">
        <div className="toolbar"><strong>Detalle de venta {sale.sale_number}</strong><span className="muted push">{warehouse?.branch_name || warehouse?.name || 'Tienda'} · {new Date(sale.sold_at).toLocaleString('es-PE')}</span></div>
        <div className="sales-workspace-grid">
            <div><small>Cliente</small><strong>{sale.customer_name || sale.customer?.name || 'Venta al público'}</strong></div>
            <div><small>Estado</small><strong>{sale.status === 'completed' ? 'Pagada' : sale.status === 'open' ? 'Por cobrar' : sale.status === 'cancelled' ? 'Anulada' : 'Devuelta'}</strong></div>
            <div><small>Total</small><strong>{money(sale.total_cents)}</strong></div>
            <div><small>Saldo</small><strong>{money(sale.total_cents - sale.paid_cents)}</strong></div>
        </div>
        <h3>Productos vendidos</h3>
        <div className="sales-workspace-lines">{sale.lines?.map((line) => <div key={line.id}>
            <span><strong>{line.product_name_snapshot}</strong><small>{line.presentation_name_snapshot} · {Number(line.quantity)} × {money(line.unit_price_cents)}</small>{Number(line.discount_cents) > 0 && <small>Descuento {money(line.discount_cents)}</small>}</span>
            <strong>{money(line.line_total_cents)}</strong>
        </div>)}</div>
        <div className="sales-workspace-payments"><strong>Pagos</strong>{sale.payments?.length ? sale.payments.map((payment) => <span key={payment.id}>{({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia', other: 'Otro' })[payment.method] || payment.method}: {money(payment.amount_cents)}</span>) : <span>Sin pagos registrados</span>}</div>
        <div className="sales-workspace-ticket-actions"><strong>Total {money(sale.total_cents)}</strong><button type="button" className="button" onClick={() => onTicket(sale)}>🧾 Ver ticket, imprimir o compartir PDF</button></div>
    </div>;
}

export default function SalesWorkspace({ warehouses, branches, warehouseId, customers, user, sunatMode, salesRevision, onTicket, onShipment }) {
    const [filters, setFilters] = useState({ q: '', status: '', document: '', from: '', to: '', page: 1 });
    const [archiveWarehouseId, setArchiveWarehouseId] = useState(warehouseId);
    const [archive, setArchive] = useState({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
    const [selectedId, setSelectedId] = useState(null);
    const [sale, setSale] = useState(null);
    const [message, setMessage] = useState('');
    const [messageOk, setMessageOk] = useState(false);
    const [busy, setBusy] = useState(false);
    const [reload, setReload] = useState(0);
    const detailRef = useRef(null);

    useEffect(() => { setArchiveWarehouseId(warehouseId); setSelectedId(null); }, [warehouseId]);

    useEffect(() => {
        let live = true;
        axios.get('/api/v1/sales/archive', { params: { ...filters, warehouse_id: archiveWarehouseId || undefined } })
            .then(({ data }) => { if (live) setArchive(data); })
            .catch((error) => { if (live) { setMessageOk(false); setMessage(errorText(error)); } });
        return () => { live = false; };
    }, [filters, archiveWarehouseId, salesRevision, reload]);

    useEffect(() => {
        if (!selectedId) { setSale(null); return; }
        let live = true;
        axios.get(`/api/v1/sales/${selectedId}`).then(({ data }) => { if (live) setSale(data.data); })
            .catch((error) => { if (live) { setMessageOk(false); setMessage(errorText(error)); } });
        return () => { live = false; };
    }, [selectedId, salesRevision, reload]);

    useEffect(() => {
        if (sale && Number(sale.id) === Number(selectedId)) detailRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [sale?.id, selectedId]);

    async function submit(event, action) {
        event.preventDefault();
        const formElement = event.currentTarget;
        setBusy(true); setMessage(''); setMessageOk(false);
        try {
            await action(new FormData(formElement));
            setReload((value) => value + 1);
            setMessage('Registro guardado.');
            setMessageOk(true);
            formElement.reset();
        } catch (error) { setMessage(errorText(error)); }
        finally { setBusy(false); }
    }

    const updateFilter = (key, value) => setFilters((current) => ({ ...current, [key]: value, page: 1 }));
    const activeWarehouse = warehouses.find((warehouse) => Number(warehouse.id) === Number(sale?.warehouse_id));
    const branchSeries = branches.find((branch) => Number(branch.id) === Number(activeWarehouse?.branch_id));
    const document = sale?.documents?.find((item) => item.environment !== 'beta');
    const betaDocument = sale?.documents?.find((item) => item.environment === 'beta');
    const canPrepareShipment = !sale?.shipments?.some((item) => item.delivery_order_id) && sale?.lines?.some((line) => {
        const assigned = sale.shipments?.filter((item) => item.status !== 'cancelled').flatMap((item) => item.lines || [])
            .filter((item) => Number(item.sale_line_id) === Number(line.id))
            .reduce((sum, item) => sum + Number(item.quantity), 0) || 0;
        return Number(line.quantity) - Number(line.returned_quantity || 0) - assigned > 0;
    });

    return <div className="sales-workspace">
        <div className="panel sales-workspace-search">
            <div className="toolbar"><strong>Ventas y documentos</strong><span className="muted push">{archive.meta.total} ventas · consulta de todas las fechas</span></div>
            <div className="sales-workspace-filters">
                <select aria-label="Filtrar tienda" value={archiveWarehouseId || ''} onChange={(event) => { setArchiveWarehouseId(event.target.value); setFilters((current) => ({ ...current, page: 1 })); }}><option value="">Todas las tiendas asignadas</option>{warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.branch_name || warehouse.name}</option>)}</select>
                <input aria-label="Buscar venta o cliente" placeholder="Número, cliente o DNI/RUC" value={filters.q} onChange={(event) => updateFilter('q', event.target.value)} />
                <select aria-label="Estado de venta" value={filters.status} onChange={(event) => updateFilter('status', event.target.value)}><option value="">Todos los estados</option><option value="open">Por cobrar</option><option value="completed">Pagadas</option><option value="cancelled">Anuladas</option><option value="returned">Devueltas</option></select>
                <select aria-label="Estado de comprobante" value={filters.document} onChange={(event) => updateFilter('document', event.target.value)}><option value="">Todos los comprobantes</option><option value="pending">Pendiente real</option><option value="external">Registrado SOL/PSE</option><option value="beta">Prueba Beta</option><option value="production">SUNAT producción</option></select>
                <label>Desde <input type="date" value={filters.from} onChange={(event) => updateFilter('from', event.target.value)} /></label>
                <label>Hasta <input type="date" value={filters.to} onChange={(event) => updateFilter('to', event.target.value)} /></label>
            </div>
            <div className="sales-workspace-list">{archive.data.map((item) => {
                const realDocument = item.documents?.find((row) => row.environment !== 'beta');
                return <button type="button" key={item.id} className={`sales-workspace-item ${Number(selectedId) === Number(item.id) ? 'selected' : ''}`} onClick={() => { if (Number(selectedId) === Number(item.id)) detailRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }); else { setSale(null); setSelectedId(item.id); } setMessage(''); }}>
                    <span><strong>{item.sale_number}</strong><small>{new Date(item.sold_at).toLocaleString('es-PE')} · {item.customer_name || item.customer?.name || 'Venta al público'}</small></span>
                    <span><strong>{money(item.total_cents)}</strong><small>{realDocument ? `${docName(realDocument.document_type)} ${realDocument.series}-${realDocument.number}` : item.documents?.length ? 'Solo prueba Beta · real pendiente' : `${docName(item.requested_document_type)} pendiente`}</small></span>
                    <span className="badge">{item.status === 'completed' ? 'Pagada' : item.status === 'open' ? 'Por cobrar' : item.status === 'cancelled' ? 'Anulada' : 'Devuelta'}</span>
                    <span className="sales-workspace-open">Ver detalle y ticket →</span>
                </button>;
            })}{!archive.data.length && <p className="empty">No hay ventas con estos filtros.</p>}</div>
            <div className="sales-workspace-pages"><button type="button" className="icon-btn" disabled={filters.page <= 1} onClick={() => setFilters((current) => ({ ...current, page: current.page - 1 }))}>Anterior</button><span>Página {archive.meta.current_page} de {archive.meta.last_page}</span><button type="button" className="icon-btn" disabled={filters.page >= archive.meta.last_page} onClick={() => setFilters((current) => ({ ...current, page: current.page + 1 }))}>Siguiente</button></div>
        </div>
        {message && <div className={messageOk ? 'success-note' : 'alert'} role="status">{message}</div>}
        {selectedId && !sale && <div className="panel"><p>Cargando venta…</p></div>}
        {sale && <div ref={detailRef} className="sales-workspace-detail">
            <SaleTicketDetail sale={sale} warehouse={activeWarehouse} onTicket={onTicket} />
            <div className="panel"><div className="toolbar"><strong>Factura o boleta</strong><span className="muted push">El ticket es interno; el comprobante fiscal se emite aparte.</span></div>
                {!document && !betaDocument && !['cancelled', 'returned'].includes(sale.status) && <form className="sales-workspace-form" onSubmit={(event) => submit(event, (form) => axios.patch(`/api/v1/sales/${sale.id}/billing`, { requested_document_type: form.get('requested_document_type'), customer_id: form.get('customer_id') || null }))}><strong>Datos de facturación antes de emitir</strong><select name="requested_document_type" defaultValue={sale.requested_document_type || 'receipt'}><option value="receipt">Boleta</option><option value="invoice">Factura</option></select><select name="customer_id" defaultValue={sale.customer_id || ''}><option value="">Venta al público</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} · {customer.tax_id || 'sin documento'}</option>)}</select><button className="button" disabled={busy}>Guardar datos</button><small>Factura: selecciona cliente con RUC. Boleta mayor a S/ 700: identifica al cliente.</small></form>}
                {betaDocument && <div className="sales-workspace-document"><strong>Prueba Beta {betaDocument.series}-{betaDocument.number}</strong><span>{betaDocument.status} · sin valor tributario</span><a className="icon-btn" href={`/api/v1/sales/documents/${betaDocument.id}/xml`}>XML</a></div>}
                {user.role === 'admin' && !betaDocument && !document && sunatMode === 'integrated' && sale.requested_document_type && <button type="button" className="button" disabled={busy} onClick={async () => { setBusy(true); setMessage(''); try { await axios.post(`/api/v1/sales/${sale.id}/sunat-document`, { document_type: sale.requested_document_type }); setReload((value) => value + 1); setMessageOk(true); setMessage('Prueba enviada a SUNAT Beta.'); } catch (error) { setMessageOk(false); setMessage(errorText(error)); } finally { setBusy(false); } }}>Enviar {docName(sale.requested_document_type)} a Beta</button>}
                {document ? <div className="sales-workspace-document"><strong>{docName(document.document_type)} {document.series}-{document.number}</strong><span>{document.environment === 'beta' ? 'Prueba SUNAT Beta, sin valor tributario' : document.environment === 'external' ? 'Registrado desde SOL/PSE · aceptación no verificada por Lions' : 'SUNAT producción'} · {document.issue_date}</span><strong>{money(document.total_cents)}</strong><div className="actions">{document.has_pdf && <a className="icon-btn" href={`/api/v1/sales/${sale.id}/documents/${document.id}/pdf`}>PDF</a>}{document.environment === 'beta' && <a className="icon-btn" href={`/api/v1/sales/documents/${document.id}/xml`}>XML de prueba</a>}{document.cdr_path && <a className="icon-btn" href={`/api/v1/sales/documents/${document.id}/cdr`}>CDR</a>}</div></div> : <p className="muted">Comprobante real pendiente de emisión o registro.</p>}
                {user.role === 'admin' && !document && sunatMode === 'manual' && !['cancelled', 'returned'].includes(sale.status) && <form className="sales-workspace-form" onSubmit={(event) => submit(event, (form) => { const payload = new FormData(); for (const key of ['document_type', 'series', 'number', 'issue_date', 'issued_via', 'pdf']) if (form.get(key) && (key !== 'pdf' || form.get(key).name)) payload.append(key, form.get(key)); payload.append('total_cents', String(Math.round(Number(form.get('total')) * 100))); return axios.post(`/api/v1/sales/${sale.id}/external-document`, payload); })}><strong>Registrar comprobante ya emitido en SOL/PSE</strong><select name="document_type" defaultValue={sale.requested_document_type || 'receipt'}><option value="receipt">Boleta</option><option value="invoice">Factura</option></select><input name="series" required maxLength="4" placeholder="Serie B001 / F001" defaultValue={sale.requested_document_type === 'invoice' ? branchSeries?.invoice_series : branchSeries?.receipt_series} /><input name="number" required inputMode="numeric" placeholder="Número" /><input name="issue_date" required type="date" max={new Date().toLocaleDateString('en-CA')} /><input name="total" required type="number" min="0.01" step="0.01" defaultValue={(sale.total_cents / 100).toFixed(2)} /><select name="issued_via"><option value="see_sol">SUNAT SOL</option><option value="pse">PSE</option></select><label>PDF del comprobante <input name="pdf" type="file" accept="application/pdf" /></label><button className="button" disabled={busy}>Vincular comprobante</button><small>Este registro no envía el documento a SUNAT. Adjunta el PDF emitido por SOL/PSE si lo tienes.</small></form>}
                {user.role === 'admin' && document?.environment === 'external' && !document.has_pdf && <form className="sales-workspace-form" onSubmit={(event) => submit(event, (form) => axios.post(`/api/v1/sales/${sale.id}/documents/${document.id}/pdf`, form))}><label>Adjuntar PDF original <input name="pdf" type="file" accept="application/pdf" required /></label><button className="button light" disabled={busy}>Guardar PDF</button></form>}
                {document?.adjustments?.map((note) => <div className="sales-workspace-document" key={note.id}><strong>{note.adjustment_type === 'credit_note' ? 'Nota de crédito' : 'Nota de débito'} {note.series}-{note.number}</strong><span>{note.issue_date} · {note.reason} · registro externo</span><strong>{money(note.total_cents)}</strong>{note.has_pdf && <a className="icon-btn" href={`/api/v1/sales/${sale.id}/documents/${document.id}/adjustments/${note.id}/pdf`}>PDF</a>}</div>)}
                {user.role === 'admin' && document?.environment === 'external' && sunatMode === 'manual' && <form className="sales-workspace-form" onSubmit={(event) => submit(event, (form) => { const payload = new FormData(); for (const key of ['adjustment_type', 'series', 'number', 'issue_date', 'reason', 'issued_via', 'pdf']) if (form.get(key) && (key !== 'pdf' || form.get(key).name)) payload.append(key, form.get(key)); payload.append('total_cents', String(Math.round(Number(form.get('total')) * 100))); return axios.post(`/api/v1/sales/${sale.id}/documents/${document.id}/adjustments`, payload); })}><strong>Registrar nota emitida en SOL/PSE</strong><select name="adjustment_type"><option value="credit_note">Nota de crédito</option><option value="debit_note">Nota de débito</option></select><input name="series" placeholder="Serie F/B" maxLength="4" required /><input name="number" placeholder="Número" inputMode="numeric" required /><input name="issue_date" type="date" max={new Date().toLocaleDateString('en-CA')} required /><input name="total" type="number" min="0" step="0.01" placeholder="Importe S/" required /><input name="reason" minLength="5" maxLength="500" placeholder="Motivo de la nota" required /><select name="issued_via"><option value="see_sol">SUNAT SOL</option><option value="pse">PSE</option></select><label>PDF de la nota <input name="pdf" type="file" accept="application/pdf" /></label><button className="button light" disabled={busy}>Vincular nota</button></form>}
            </div>
            <div className="panel"><div className="toolbar"><strong>Envíos y guías de remisión</strong><span className="muted push">Una guía por despacho cuando corresponda</span></div>{sale.shipments?.map((shipment) => <div className="sales-workspace-document" key={shipment.id}><strong>Despacho #{shipment.id}</strong><span>{shipment.status === 'prepared' ? 'Preparado' : shipment.status === 'dispatched' ? 'Despachado' : 'Cancelado'} · {shipment.lines?.map((line) => `${line.sale_line?.product_name_snapshot || 'Producto'} × ${line.quantity}`).join(', ')}</span><strong>{shipment.guide ? `GRE ${shipment.guide.series}-${shipment.guide.number}` : shipment.guide_required ? 'GRE pendiente' : 'Sin GRE requerida'}</strong>{shipment.status === 'prepared' && <button type="button" className="icon-btn" onClick={() => onShipment(sale, shipment)}>Confirmar despacho</button>}</div>)}{!sale.shipments?.length && <p className="muted">Sin envíos preparados.</p>}{!['cancelled', 'returned'].includes(sale.status) && canPrepareShipment && <button type="button" className="button light" onClick={() => onShipment(sale)}>Preparar envío</button>}</div>
        </div>}
    </div>;
}
