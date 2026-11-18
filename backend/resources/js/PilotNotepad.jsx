import React, { useEffect, useState } from 'react';
import axios from 'axios';

const money = (cents) => `S/ ${(Number(cents || 0) / 100).toFixed(2)}`;
const errorText = (error) => Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'No se pudo guardar el apunte.';

export default function PilotNotepad({ warehouseId, products, revision, onRegularize }) {
    const [notes, setNotes] = useState([]);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [selected, setSelected] = useState({});
    const [reload, setReload] = useState(0);

    useEffect(() => {
        if (!warehouseId) return;
        let live = true;
        axios.get('/api/v1/pilot-notes', { params: { warehouse_id: warehouseId } })
            .then(({ data }) => { if (live) setNotes(data.data); })
            .catch((e) => { if (live) setError(errorText(e)); });
        return () => { live = false; };
    }, [warehouseId, revision, reload]);

    async function save(event) {
        event.preventDefault(); setError(''); setBusy(true);
        const form = event.currentTarget;
        const data = new FormData(form);
        try {
            await axios.post('/api/v1/pilot-notes', {
                warehouse_id: Number(warehouseId), product_name: data.get('product_name').trim(), quantity: data.get('quantity'),
                unit_price_cents: Math.round(Number(data.get('unit_price')) * 100), customer_name: data.get('customer_name') || null,
                payment_method: data.get('payment_method'), remarks: data.get('remarks') || null,
            });
            form.reset(); setReload((n) => n + 1);
        } catch (e) { setError(errorText(e)); }
        finally { setBusy(false); }
    }

    return <section id="pilot-notepad" className="panel pilot-notepad">
        <div className="toolbar"><strong>📝 Bloc rápido de ventas piloto</strong><span className="muted push">{notes.filter((n) => n.status === 'pending').length} apuntes pendientes</span></div>
        <p className="muted">Anota una unidad de producto por renglón cuando aún no exista en el catálogo. El apunte no crea venta, ticket ni comprobante. Al regularizarlo, se registra la venta una sola vez.</p>
        {error && <div className="alert">{error}</div>}
        <form className="pilot-note-form" onSubmit={save}>
            <label>Producto vendido<input name="product_name" required maxLength="180" placeholder="Nombre o descripción" /></label>
            <label>Cantidad<input name="quantity" type="number" min="0.000001" step="0.000001" defaultValue="1" required /></label>
            <label>Precio unitario S/<input name="unit_price" type="number" min="0.01" step="0.01" required /></label>
            <label>Medio de pago<select name="payment_method"><option value="cash">Efectivo</option><option value="card">Tarjeta</option><option value="transfer">Transferencia</option><option value="other">Otro</option></select></label>
            <label>Cliente (opcional)<input name="customer_name" maxLength="180" /></label>
            <label>Nota (opcional)<input name="remarks" maxLength="500" placeholder="Color, talla, referencia…" /></label>
            <button className="button" disabled={busy}>Guardar apunte</button>
        </form>
        <div className="pilot-note-list">{notes.map((note) => <div className="pilot-note-row" key={note.id}>
            <div><strong>{note.product_name}</strong><small>{new Date(note.sold_at).toLocaleString('es-PE')} · {note.quantity} × {money(note.unit_price_cents)} · {note.customer_name || 'Venta al público'}</small>{note.remarks && <small>{note.remarks}</small>}</div>
            <strong>{money(Math.round(Number(note.quantity) * note.unit_price_cents))}</strong>
            {note.status === 'pending' ? <div className="pilot-note-actions"><select aria-label={`Producto para regularizar ${note.product_name}`} value={selected[note.id] || ''} onChange={(e) => setSelected((s) => ({ ...s, [note.id]: e.target.value }))}><option value="">Vincular producto del catálogo</option>{products.filter((p) => p.is_active).map((p) => <option key={p.id} value={p.id}>{p.name} · {p.sku}</option>)}</select><button type="button" className="button light" disabled={!selected[note.id]} onClick={() => onRegularize(note, products.find((p) => String(p.id) === String(selected[note.id])))}>Pasar al carrito</button></div>
                : <span className="badge">Regularizada · venta #{note.sale_id}</span>}
        </div>)}{!notes.length && <p className="muted">Aún no hay apuntes en esta tienda.</p>}</div>
    </section>;
}
