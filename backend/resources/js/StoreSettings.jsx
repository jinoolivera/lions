import React from 'react';

export default function StoreSettings({ branches, company, users, saveBranch, saveCompany, saveStoreUser }) {
    return <div className="store-settings">
        <section className="panel store-settings-panel">
            <div className="toolbar"><strong>Tiendas</strong><span className="muted">Los nombres y direcciones se pueden actualizar sin mover el inventario.</span></div>
            <div className="store-settings-grid">{branches.map((branch) => <form key={branch.id} className="store-card" onSubmit={(event) => saveBranch(event, branch)}>
                <div className="eyebrow">{branch.code}</div>
                <div className="field"><label>Nombre de la tienda</label><input name="name" defaultValue={branch.name} required minLength="2" maxLength="120" /></div>
                <div className="field"><label>Dirección de la tienda</label><input name="address" defaultValue={branch.address || ''} maxLength="240" placeholder="Dirección comercial" /></div>
                <div className="field"><label>Serie de factura</label><input name="invoice_series" defaultValue={branch.invoice_series} pattern="F[A-Z0-9]{3}" maxLength="4" required /></div>
                <div className="field"><label>Serie de boleta</label><input name="receipt_series" defaultValue={branch.receipt_series} pattern="B[A-Z0-9]{3}" maxLength="4" required /></div>
                <small>Próximos números Beta: factura {branch.next_invoice_beta} · boleta {branch.next_receipt_beta}. Cambiar a una serie nueva reinicia el correlativo de esa serie.</small>
                <small>{branch.warehouses?.map((warehouse) => warehouse.name).join(' · ')}</small>
                <button className="button">Guardar tienda</button>
            </form>)}</div>
        </section>
        <section className="panel store-settings-panel">
            <div className="toolbar"><strong>Datos fiscales compartidos</strong><span className="muted">Un mismo RUC, razón social y dirección fiscal para ambas tiendas.</span></div>
            {company && <form key={JSON.stringify(company)} className="store-settings-grid store-company" onSubmit={saveCompany}>
                <div className="field"><label>RUC</label><input name="ruc" inputMode="numeric" pattern="[0-9]{11}" maxLength="11" defaultValue={company.ruc || ''} /></div>
                <div className="field"><label>Razón social</label><input name="legal_name" maxLength="180" defaultValue={company.legal_name || ''} /></div>
                <div className="field"><label>Nombre comercial</label><input name="trade_name" maxLength="180" defaultValue={company.trade_name || ''} /></div>
                <div className="field"><label>Ubigeo</label><input name="ubigeo" inputMode="numeric" pattern="[0-9]{6}" maxLength="6" defaultValue={company.ubigeo || ''} /></div>
                <div className="field store-wide"><label>Dirección fiscal</label><input name="fiscal_address" maxLength="240" defaultValue={company.fiscal_address || ''} /></div>
                <div className="store-wide"><button className="button">Guardar datos fiscales</button></div>
            </form>}
        </section>
        <section className="panel store-settings-panel">
            <div className="toolbar"><strong>Usuarios por tienda</strong><span className="muted">Cada usuario accede solo a las tiendas asignadas. Administración accede a todas.</span></div>
            <div className="store-settings-grid">
                <form className="store-card" onSubmit={(event) => saveStoreUser(event)}>
                    <strong>Nuevo usuario</strong>
                    <div className="field"><label>Nombre</label><input name="name" required minLength="2" maxLength="120" /></div>
                    <div className="field"><label>Correo</label><input name="email" type="email" required /></div>
                    <div className="field"><label>Contraseña</label><input name="password" type="password" minLength="8" required autoComplete="new-password" /></div>
                    <div className="field"><label>Rol</label><select name="role"><option value="cashier">Cajero</option><option value="manager">Encargado</option></select></div>
                    <div className="field"><label>Tiendas permitidas</label><div className="store-checks">{branches.map((branch) => <label key={branch.id}><input type="checkbox" name="branch_ids" value={branch.id} /> {branch.name}</label>)}</div></div>
                    <button className="button">Crear usuario</button>
                </form>
                {users.filter((person) => person.role !== 'admin').map((person) => <form key={person.id} className="store-card" onSubmit={(event) => saveStoreUser(event, person)}>
                    <strong>{person.name}</strong>
                    <div className="field"><label>Nombre</label><input name="name" defaultValue={person.name} required minLength="2" maxLength="120" /></div>
                    <div className="field"><label>Correo</label><input name="email" type="email" defaultValue={person.email} required /></div>
                    <div className="field"><label>Nueva contraseña (opcional)</label><input name="password" type="password" minLength="8" autoComplete="new-password" /></div>
                    <div className="field"><label>Rol</label><select name="role" defaultValue={person.role}><option value="cashier">Cajero</option><option value="manager">Encargado</option></select></div>
                    <div className="field"><label>Tiendas permitidas</label><div className="store-checks">{branches.map((branch) => <label key={branch.id}><input type="checkbox" name="branch_ids" value={branch.id} defaultChecked={person.branches?.some((assigned) => assigned.id === branch.id)} /> {branch.name}</label>)}</div></div>
                    <button className="button light">Guardar usuario</button>
                </form>)}
            </div>
        </section>
    </div>;
}
