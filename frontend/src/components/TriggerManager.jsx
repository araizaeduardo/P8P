import { useEffect, useState } from 'react';
import { apiFetch } from '../api/client';

const fresh = () => ({ id: '', node_id: '', provider_key: '', credential_id: '', config: {}, public_url: '', interval_seconds: 60 });
const input = 'w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100';
const button = 'rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-200 hover:bg-slate-800 disabled:opacity-40';
const statusNames = { active: 'Activo', paused: 'Pausado', draft: 'Borrador', error: 'Error', pending: 'Pendiente', ready: 'Preparado', disabled: 'Desactivado', awaiting_event: 'Esperando evento', awaiting_baseline: 'Esperando primera lectura', retry_wait: 'Esperando reintento', awaiting_verification: 'Esperando verificación' };

export default function TriggerManager({ workflowId, nodes = [], onClose }) {
    const [catalog, setCatalog] = useState([]);
    const [triggers, setTriggers] = useState([]);
    const [credentials, setCredentials] = useState([]);
    const [active, setActive] = useState(false);
    const [form, setForm] = useState(fresh);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [revision, setRevision] = useState(0);

    useEffect(() => {
        let cancelled = false;
        async function load() {
            setLoading(true);
            setError('');
            if (!workflowId) {
                setLoading(false);
                return;
            }
            try {
                const [result, credentialResult] = await Promise.all([
                    apiFetch(`./triggers.php?workflow_id=${encodeURIComponent(workflowId)}`), apiFetch('./credentials.php'),
                ]);
                if (!result.success || !credentialResult.success) throw new Error(result.error || credentialResult.error || 'No se pudo cargar la configuración.');
                if (!cancelled) {
                    setCatalog(Array.isArray(result.catalog) ? result.catalog : []);
                    setTriggers(Array.isArray(result.triggers) ? result.triggers : []);
                    setCredentials(Array.isArray(credentialResult.credentials) ? credentialResult.credentials : []);
                    setActive(Boolean(result.workflow_active));
                }
            } catch (e) {
                if (!cancelled) setError(e.message || 'No se pudo cargar la configuración.');
            } finally {
                if (!cancelled) setLoading(false);
            }
        }
        load();
        return () => { cancelled = true; };
    }, [workflowId, revision]);

    const selected = catalog.find((item) => item.key === form.provider_key);
    const starts = nodes.filter((node) => {
        const type = node.data?.type || node.type;
        return type !== 'chat_trigger' && (type === 'start' || type === 'service_trigger' || node.data?.isStart || node.data?.is_start);
    });
    const fields = Object.entries(selected?.config_fields || {});
    const secrets = Object.entries(selected?.secret_fields || {});

    async function mutate(payload, message) {
        if (!workflowId) {
            setError('Guarda el workflow antes de configurar triggers.');
            return false;
        }
        setBusy(true); setError(''); setNotice('');
        try {
            const result = await apiFetch('./triggers.php', { method: 'POST', body: JSON.stringify(payload) });
            if (!result.success) throw new Error(result.error || 'No se pudo completar la operación.');
            setNotice(message); setRevision((value) => value + 1);
            return true;
        } catch (e) {
            setError(e.message || 'No se pudo completar la operación.');
            return false;
        } finally {
            setBusy(false);
        }
    }

    async function save(event) {
        event.preventDefault();
        const config = {};
        for (const [name, metadata] of fields) {
            const value = form.config[name];
            if (value !== undefined && value !== '') config[name] = metadata.type === 'number' ? Number(value) : value;
        }
        const payload = { action: 'save', workflow_id: workflowId, node_id: form.node_id, provider_key: form.provider_key,
            credential_id: form.credential_id, config, public_url: form.public_url.trim(), interval_seconds: Number(form.interval_seconds) };
        if (form.id) payload.id = form.id;
        if (await mutate(payload, 'Configuración guardada. Revisa los requisitos externos antes de activar.')) setForm(fresh());
    }

    function edit(trigger) {
        setForm({ id: trigger.id, node_id: trigger.node_id || '', provider_key: trigger.provider_key || '',
            credential_id: trigger.credential_id || '', config: trigger.config || {}, public_url: trigger.public_url || '', interval_seconds: trigger.interval_seconds || 60 });
        setError(''); setNotice('');
    }

    async function copy(url) {
        try { await navigator.clipboard.writeText(url); setNotice('URL copiada.'); }
        catch { setError('No se pudo copiar. Selecciona la URL y cópiala manualmente.'); }
    }

    async function copyNotionSetup(id) {
        setBusy(true); setError(''); setNotice('');
        try {
            const result = await apiFetch('./triggers.php', { method: 'POST', body: JSON.stringify({ action: 'setup_secret', id }) });
            if (!result.success || typeof result.setup_secret?.verification_token !== 'string') throw new Error('No se pudo recuperar el token de verificación.');
            await navigator.clipboard.writeText(result.setup_secret.verification_token);
            setNotice('Token copiado. Comprueba la suscripción que acabas de crear en Notion, verifica allí y guárdalo también en una credencial cifrada antes de activar.');
        } catch {
            setError('No se pudo copiar el token de verificación. Permite el acceso al portapapeles y vuelve a intentarlo.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" role="dialog" aria-modal="true" aria-labelledby="trigger-manager-title">
            <div className="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl">
                <div className="flex items-center justify-between border-b border-slate-800 p-5">
                    <div><h2 id="trigger-manager-title" className="text-lg font-semibold text-white">Triggers del workflow</h2><p className="mt-1 text-xs text-slate-400">Conecta un evento a un nodo inicial de este workflow guardado.</p></div>
                    <button type="button" onClick={onClose} className={button} aria-label="Cerrar configuración de triggers">Cerrar</button>
                </div>
                <div className="space-y-5 overflow-y-auto p-5">
                    {error && <p role="alert" className="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-300">{error}</p>}
                    {notice && <p role="status" className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-300">{notice}</p>}
                    {!workflowId && <p className="rounded-lg border border-amber-500/30 p-3 text-sm text-amber-300">Guarda el workflow antes de configurar triggers.</p>}
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-700 p-3">
                        <div><p className="text-sm text-slate-100">Workflow {active ? 'habilitado' : 'deshabilitado'}</p><p className="text-xs text-slate-400">Un workflow deshabilitado no recibe nuevas ejecuciones por triggers.</p></div>
                        <div className="flex gap-2">
                            <button type="button" disabled={busy || loading || !workflowId} className={button} onClick={() => mutate({ action: 'workflow_state', workflow_id: workflowId, enabled: !active }, active ? 'Workflow deshabilitado.' : 'Workflow habilitado.')}>{active ? 'Deshabilitar workflow' : 'Habilitar workflow'}</button>
                            <button type="button" disabled={busy || loading} onClick={() => setRevision((value) => value + 1)} className={button}>Actualizar</button>
                        </div>
                    </div>
                    {loading && <p className="text-sm text-slate-400" role="status">Cargando estado…</p>}
                    <div className="grid gap-5 lg:grid-cols-2">
                        <form onSubmit={save} className="space-y-4 rounded-xl border border-slate-800 p-4">
                            <h3 className="font-semibold text-slate-100">{form.id ? 'Editar configuración' : 'Nuevo trigger'}</h3>
                            <label className="block space-y-1 text-xs text-slate-300"><span>Evento</span>
                                <select required disabled={busy || loading} value={form.provider_key} onChange={(event) => setForm((current) => ({ ...current, provider_key: event.target.value, config: {} }))} className={input}>
                                    <option value="">Selecciona un evento</option>{catalog.map((item) => <option key={item.key} value={item.key}>{item.name} · {item.transport === 'polling' ? 'Polling' : 'Webhook'}</option>)}
                                </select>
                            </label>
                            <label className="block space-y-1 text-xs text-slate-300"><span>Nodo inicial receptor</span>
                                <select required disabled={busy || loading} value={form.node_id} onChange={(event) => setForm((current) => ({ ...current, node_id: event.target.value }))} className={input}>
                                    <option value="">Selecciona un nodo inicial guardado</option>{starts.map((node) => <option key={node.id} value={node.id}>{node.data?.label || node.data?.name || node.id} ({node.id})</option>)}
                                </select>
                            </label>
                            {starts.length === 0 && <p className="text-xs text-amber-300">Añade un nodo Inicio o Trigger de servicio y guarda el workflow antes de configurar el evento.</p>}
                            <label className="block space-y-1 text-xs text-slate-300"><span>Credencial cifrada</span>
                                <select required={!form.provider_key.startsWith('notion_webhook_')} disabled={busy || loading} value={form.credential_id} onChange={(event) => setForm((current) => ({ ...current, credential_id: event.target.value }))} className={input}>
                                    <option value="">Selecciona una credencial</option>{credentials.map((credential) => <option key={credential.id} value={credential.id}>{credential.name} ({credential.type})</option>)}
                                </select>
                            </label>
                            {secrets.length > 0 && <div className="rounded-lg bg-slate-950 p-3 text-xs text-slate-400"><p>En el gestor de credenciales, guarda el JSON requerido en API Key de una credencial Bearer Token / API Key.</p><ul className="mt-2 space-y-1">{secrets.map(([name, meta]) => <li key={name}><code className="text-indigo-300">{name}</code>: {meta.label}{meta.required ? ' (obligatorio)' : ''}</li>)}</ul></div>}
                            {form.provider_key.startsWith('notion_webhook_') && <p className="text-xs text-amber-300">Primero guarda el borrador sin credencial y registra su callback en Notion. Cuando llegue el token, cópialo desde esta configuración y verifica la suscripción en Notion. Guarda verification_token en una credencial cifrada, edita esta configuración y selecciónala antes de activar. Comprueba que la solicitud coincide con la suscripción que acabas de crear; una recepción inicial no activa el trigger.</p>}
                            {fields.map(([name, meta]) => (
                                <label key={name} className="block space-y-1 text-xs text-slate-300"><span>{meta.label || name}{meta.required ? ' *' : ''}</span>
                                    {meta.options ? <select required={Boolean(meta.required)} disabled={busy} value={form.config[name] ?? ''} onChange={(event) => setForm((current) => ({ ...current, config: { ...current.config, [name]: event.target.value } }))} className={input}>
                                        <option value="">Selecciona</option>{meta.options.map((option) => <option key={option.value ?? option} value={option.value ?? option}>{option.label ?? option}</option>)}
                                    </select> : <input required={Boolean(meta.required)} disabled={busy} type={meta.type === 'number' ? 'number' : 'text'} value={form.config[name] ?? ''} onChange={(event) => setForm((current) => ({ ...current, config: { ...current.config, [name]: event.target.value } }))} className={input} />}
                                </label>
                            ))}
                            {selected?.transport === 'webhook' && <label className="block space-y-1 text-xs text-slate-300"><span>URL pública HTTPS de la carpeta del backend</span><input required type="url" pattern="https://.*" placeholder="https://p8p.example.com" disabled={busy} value={form.public_url} onChange={(event) => setForm((current) => ({ ...current, public_url: event.target.value }))} className={input} /><span className="block text-slate-500">La URL de callback se genera al guardar. Usa esa URL exacta en el proveedor.</span></label>}
                            {selected?.transport === 'polling' && <label className="block space-y-1 text-xs text-slate-300"><span>Intervalo en segundos (mínimo 60)</span><input required type="number" min="60" max="86400" disabled={busy} value={form.interval_seconds} onChange={(event) => setForm((current) => ({ ...current, interval_seconds: event.target.value }))} className={input} /><span className="block text-slate-500">Cron debe ejecutarse cada minuto. La primera lectura evita el histórico.</span></label>}
                            {selected && <div className="space-y-2 text-xs text-slate-400"><p>{selected.help}</p>{(Array.isArray(selected.docs) ? selected.docs : [selected.docs]).filter((url) => typeof url === 'string' && url.startsWith('https://')).map((url) => <a key={url} href={url} target="_blank" rel="noopener noreferrer" className="block break-all text-indigo-300 underline">Documentación del proveedor</a>)}</div>}
                            <div className="flex flex-wrap gap-2"><button type="submit" disabled={busy || loading || !workflowId || !selected || starts.length === 0} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-40">{busy ? 'Guardando…' : 'Guardar configuración'}</button>{form.id && <button type="button" disabled={busy} onClick={() => setForm(fresh())} className={button}>Cancelar edición</button>}</div>
                        </form>
                        <div className="space-y-3">
                            <h3 className="font-semibold text-slate-100">Configuraciones guardadas</h3>
                            {!loading && triggers.length === 0 && <p className="text-sm text-slate-400">Todavía no hay triggers configurados.</p>}
                            {triggers.map((trigger) => {
                                const definition = catalog.find((item) => item.key === trigger.provider_key);
                                const callback = trigger.callback_url || '';
                                return <article key={trigger.id} className="space-y-3 rounded-xl border border-slate-700 p-4">
                                    <div className="flex items-start justify-between gap-3"><div><h4 className="text-sm font-semibold text-slate-100">{definition?.name || trigger.provider_key}</h4><p className="text-xs text-slate-500">Nodo {trigger.node_id}</p></div><span className="rounded bg-slate-800 px-2 py-1 text-xs text-slate-300">{statusNames[trigger.status] || trigger.status || 'Pendiente'}</span></div>
                                    <dl className="grid grid-cols-2 gap-2 text-xs"><dt className="text-slate-500">Última recepción</dt><dd className="break-all text-slate-300">{trigger.last_received_at || 'Sin recepción'}</dd><dt className="text-slate-500">Último intento</dt><dd className="break-all text-slate-300">{trigger.last_attempt_at || 'Sin intento'}</dd><dt className="text-slate-500">Próximo intento</dt><dd className="break-all text-slate-300">{trigger.next_poll_at || trigger.next_attempt_at || '—'}</dd></dl>
                                    {(trigger.last_error || trigger.error) && <p className="break-words text-xs text-red-300">{trigger.last_error || trigger.error}</p>}
                                    {callback && <div className="space-y-2"><label className="block text-xs text-slate-400">Callback<input readOnly value={callback} onFocus={(event) => event.target.select()} className={`${input} mt-1 text-xs`} /></label><button type="button" className={button} onClick={() => copy(callback)}>Copiar callback</button></div>}
                                    {trigger.setup_available && <button type="button" disabled={busy} onClick={() => copyNotionSetup(trigger.id)} className={button}>Copiar token de verificación Notion</button>}
                                    {definition?.transport === 'webhook' && <p className="text-xs text-amber-300">Registra y valida esta URL en el proveedor. Guardar o activar aquí no crea una suscripción remota.</p>}
                                    <p className="text-xs text-slate-500">Expresiones: {'{{trigger.data}}'} y salida del nodo inicial.</p>
                                    <div className="flex flex-wrap gap-2"><button type="button" disabled={busy} onClick={() => edit(trigger)} className={button}>Editar</button><button type="button" disabled={busy || !active || trigger.status === 'active'} onClick={() => mutate({ action: 'activate', id: trigger.id }, 'Trigger habilitado para eventos verificados.')} className={button}>Activar</button><button type="button" disabled={busy || trigger.status === 'paused'} onClick={() => mutate({ action: 'pause', id: trigger.id }, 'Trigger pausado.')} className={button}>Pausar</button><button type="button" disabled={busy} onClick={() => mutate({ action: 'delete', id: trigger.id }, 'Configuración eliminada. Revisa su baja en el proveedor.')} className={`${button} text-red-300`}>Eliminar</button></div>
                                </article>;
                            })}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
