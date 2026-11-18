import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';

const errorText = (error) => Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'No se pudo guardar el apunte.';

export default function PilotNotepad({ warehouseId, revision }) {
    const [notes, setNotes] = useState([]);
    const [body, setBody] = useState('');
    const [search, setSearch] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [reload, setReload] = useState(0);
    const input = useRef(null);

    useEffect(() => {
        if (!warehouseId) return;
        let live = true;
        setNotes([]);
        const timer = setTimeout(() => axios.get('/api/v1/pilot-notes', { params: { warehouse_id: warehouseId, q: search.trim() || undefined } })
            .then(({ data }) => { if (live) setNotes(data.data); })
            .catch((e) => { if (live) setError(errorText(e)); }), search ? 250 : 0);
        return () => { live = false; clearTimeout(timer); };
    }, [warehouseId, revision, reload, search]);

    async function save(event) {
        event?.preventDefault();
        if (!body.trim() || busy) return;
        setBusy(true); setError('');
        try {
            await axios.post('/api/v1/pilot-notes', { warehouse_id: Number(warehouseId), body: body.trim() });
            setBody(''); setReload((n) => n + 1); input.current?.focus();
        } catch (e) { setError(errorText(e)); }
        finally { setBusy(false); }
    }

    return <section id="pilot-notepad" className="panel pilot-notepad">
        <div className="toolbar"><strong>📝 Bloc rápido de ventas</strong><span className="muted push">{notes.length} apuntes recientes</span></div>
        <p className="muted">Escribe lo vendido, cantidad, precio y cualquier dato útil. Se guarda con fecha y hora de esta tienda. Después podrás consultar el apunte para crear el producto y registrar la venta.</p>
        {error && <div className="alert">{error}</div>}
        <form className="pilot-quick-form" onSubmit={save}>
            <textarea ref={input} aria-label="Nuevo apunte de venta" value={body} onChange={(e) => setBody(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); save(); } }} maxLength="4000" rows="3" placeholder="Ej.: 2 pulseras doradas, S/ 20 c/u, pagó en efectivo, cliente Ana" required />
            <div><small>Enter guarda · Mayús + Enter crea otra línea</small><button className="button" disabled={busy || !body.trim()}>{busy ? 'Guardando…' : 'Guardar apunte'}</button></div>
        </form>
        <div className="pilot-note-search"><label>Consultar apuntes<input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Buscar producto, cliente o dato anotado" /></label></div>
        <div className="pilot-quick-list">{notes.map((note) => <article className="pilot-quick-entry" key={note.id}>
            <time dateTime={note.sold_at}>{new Date(note.sold_at).toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' })}</time>
            <p>{note.body || [note.product_name, `${note.quantity} × S/ ${(Number(note.unit_price_cents || 0) / 100).toFixed(2)}`, note.customer_name, note.remarks].filter(Boolean).join(' · ')}</p>
            {note.status === 'regularized' && <small>Regularizado en venta #{note.sale_id}</small>}
        </article>)}{!notes.length && <p className="muted">{search ? 'Sin apuntes que coincidan con la búsqueda.' : 'Todavía no hay apuntes en esta tienda.'}</p>}</div>
    </section>;
}
