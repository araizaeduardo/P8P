# Adaptadores de webhooks

Revisión de protocolos oficiales: 25 de octubre de 2026. `WebhookProviders` implementa 32 definiciones puras; no registra suscripciones, no carga entorno y no llama APIs. El servicio compartido resuelve la configuración receptora, descifra su credencial y almacena/encola los eventos antes de responder. Las acciones existentes conservan sus identificadores y requieren un nodo de inicio `service_trigger` independiente.

Todos los adaptadores de esta tabla están **listos para configuración externa**, con pruebas simuladas. Ningún webhook remoto fue registrado ni está declarado activo por estas pruebas. No hay expiración periódica documentada para estas suscripciones: la baja o pausa se hace también en el proveedor. La desactivación local rechaza nuevas ejecuciones; no elimina la suscripción remota.

| Nodo de acción | Clave / evento exacto | Configuración pública | Secreto cifrado | Registro y permisos | Identidad / entrega filtrada |
| --- | --- | --- | --- | --- | --- |
| GitHub | `github_push`, `github_issues`, `github_pull_request` / `push`, `issues`, `pull_request` | `repository_id` numérico como texto | `webhook_secret` | Settings → Webhooks del repositorio, JSON, eventos elegidos, secret; administrar webhooks | SHA256 cuerpo original; ref/antes/después o issue/PR, repositorio mínimo |
| WooCommerce | `woocommerce_{order,product,customer}_{created,updated,deleted}` / `order.created` etc. | Ninguna | `webhook_secret` **único por webhook** | WooCommerce → Ajustes → Avanzado → Webhooks, tema exacto y secret explícito; administrador | SHA256 cuerpo; ID, estado, importes, campos básicos y line_items filtrados |
| ClickUp | `clickup_task_created`, `clickup_task_updated`, `clickup_task_status_updated` / `taskCreated`, `taskUpdated`, `taskStatusUpdated` | `webhook_id` | `webhook_secret` devuelto al registrar | Crear webhook API v2 del workspace con token autorizado; copiar ID y secreto | `webhook_id:history_item_id` si existe; SHA256 cuerpo en otro caso; IDs y cambios |
| Linear | `linear_issue_created`, `linear_issue_updated` / `Issue.create`, `Issue.update` | `organization_id` | `webhook_secret` | Workspace Settings → API/Webhooks, seleccionar Issues; permisos para administrar integración | ID semántico de issue, acción, fecha y datos filtrados; firma+timestamp del cuerpo ≤60 s |
| Intercom | `intercom_contact_created`, `intercom_lead_created`, `intercom_conversation_created` / `contact.user.created`, `contact.lead.created`, `conversation.user.created` | `app_id` | `client_secret` de la app | Developer Hub → app → Webhooks; Read users and companies o Read conversations | ID de notificación; item mínimo, sin cuerpo completo de conversación |
| Zendesk | `zendesk_ticket_event` / notificación configurada por regla | Ninguna | `webhook_secret` de Zendesk | Admin Center → Webhooks, JSON; Trigger/Automation envía cuerpo documentado abajo | `event_id` obligatorio en cuerpo; ticket, estado, prioridad y comentario explícito |
| Typeform | `typeform_response` / `form_response` completo | `form_id` | `webhook_secret` | Connect → Webhooks o PUT `/forms/{form_id}/webhooks/{tag}` con secret; acceso al formulario | `event_id`; form_response con answers, fechas e ID de respuesta |
| Slack | `slack_message`, `slack_app_mention` / `message`, `app_mention` | `team_id` | `signing_secret` | App → Event Subscriptions → Request URL, eventos bot; scopes `channels:history`/`groups:history`/`im:history`/`mpim:history` según canal o `app_mentions:read`; instalar app | `event_id`; mensaje filtrado; no `token` de verificación antiguo |
| Telegram | `telegram_message` / Update.message | Ninguna | `secret_token` | `setWebhook` del bot con URL exacta y `secret_token`; `allowed_updates=["message"]`; un webhook por bot | `update_id`; mensaje, chat y remitente mínimos |
| WhatsApp Business | `whatsapp_message`, `whatsapp_status` / `messages` y `statuses` dentro de campo `messages` | `waba_id`, `phone_number_id` | `app_secret`, `verify_token` | Meta App Webhooks → whatsapp_business_account/messages y suscripción WABA; administración autorizada de esa WABA | ID mensaje o ID:estado; texto o ID de medio; no descarga automática |
| Twilio | `twilio_sms_received`, `twilio_call_received` / webhook entrante Messaging o Voice | `account_sid` | `auth_token` de cuenta | Número → Messaging/Voice → webhook POST, callback exacto; acceso a número/cuenta | MessageSid/CallSid; campos entrantes; ACK TwiML vacío |
| Brevo | `brevo_transactional`, `brevo_marketing` / campo `event` configurado | `event` exacto del payload | `webhook_token` | Crear webhook con `auth.type=bearer`, `auth.token` propio, tipo y eventos elegidos; API key autorizada solo para registrar | SHA256 de cada objeto; email, ID, campaña, evento y timestamps |
| Trello | `trello_action` / action sobre modelo | `model_id` | `app_secret` (OAuth1) o client secret (OAuth2) | API webhook con callback exacto, token con acceso a modelo; responde HEAD de registro | `action.id`; modelo, acción, tarjeta/lista/tablero mínimos |

## Firma, ACK y repetición

GitHub y WhatsApp: HMAC SHA256 hexadecimal con prefijo `sha256=`. WooCommerce: HMAC SHA256 binario/base64. ClickUp y Linear: HMAC SHA256 hexadecimal. Intercom: HMAC SHA1 hexadecimal con prefijo `sha1=`. Zendesk: base64 HMAC SHA256 de timestamp+cuerpo, tolerancia local de cinco minutos. Typeform: `sha256=` + base64 HMAC SHA256. Slack: `v0=` + HMAC SHA256 de `v0:timestamp:cuerpo`, tolerancia oficial de cinco minutos. Trello: base64 HMAC SHA1 de cuerpo+callback URL exacta. Siempre se comparan con `hash_equals` sobre los bytes originales, antes de JSON.

Telegram autentica con cabecera `X-Telegram-Bot-Api-Secret-Token`; Brevo con `Authorization: Bearer ...`. Esos protocolos usan TLS y secreto compartido estático, no una firma del cuerpo ni timestamp firmado. Un tercero que conozca el secreto puede emitir peticiones: no se presenta como prueba criptográfica de origen más fuerte que la disponible oficialmente.

Twilio acepta exclusivamente formularios URL encoded entrantes: concatena URL HTTPS canónica exacta (incluida query), claves ordenadas y valores decodificados; HMAC SHA1/base64. Rechaza claves ambiguas, repetidas o sintaxis de arrays en lugar de truncarlas con `parse_str`. No admite JSON/bodySHA256 ni callbacks de estado mediante estos dos triggers. TwiML vacío confirma recepción y **termina la llamada sin diálogo**; para IVR se necesita otro contrato de respuesta síncrona. SMS no envía respuesta automática.

Slack responde `challenge` después de verificar firma. Meta distingue GET challenge con verify token y POST firmado con app secret. Trello responde HEAD sin crear evento. El ping de activación WooCommerce es oficialmente un formulario `webhook_id=N` sin firma: se devuelve ACK y **nunca** se almacena como evento ni demuestra autenticidad/actividad. Ninguno de los handshakes marca recepción de negocio.

Los encabezados GitHub delivery y WooCommerce delivery/topic no están cubiertos por su firma del cuerpo. No se usan como identidad deduplicable: se usa hash del cuerpo. Para WooCommerce es obligatorio un secreto diferente por webhook; no reutilizar un secret entre tiendas o temas. GitHub comprueba además repository.id firmado. Linear omite webhookTimestamp de la identidad para mantenerla en reintentos firmados de nuevo; ClickUp divide history_items. Las firmas sin timestamp no permiten rechazar criptográficamente replays antiguos: la deduplicación duradera del core conserva IDs; si se purgan esos registros, se pierde esa protección. Dos notificaciones legítimas con cuerpo idéntico sin ID estable pueden colapsarse. No se promete exactamente una ejecución o efecto.

Los eventos fuera de orden son entregados como llegaron; no se descartan por comparación de fecha. El workflow debe decidir si una actualización obsoleta modifica su destino.

## Configuración práctica

Crear una credencial `generic_bearer` y guardar en **API Key** el objeto JSON con las claves secretas de la tabla. Ejemplo ficticio: `{"webhook_secret":"valor-aleatorio-exclusivo-del-webhook"}`. No copiar secretos a parámetros del workflow, URL ni encabezados de ejemplo públicos. Conectar la credencial por ID al trigger; el backend central descifra el JSON.

Configurar campos públicos, workflow y nodo de inicio antes de habilitar el receptor. Copiar la URL HTTPS generada por P8P exactamente al proveedor. Twilio y Trello la incluyen en la firma: no reordenar query ni deducirla de un Host recibido. Configurar cron cada minuto para ejecutar la cola después de recepción; no ejecutar acciones largas dentro del webhook.

Zendesk necesita cuerpo JSON propio, por ejemplo `{"event_id":"ticket-{{ticket.id}}-{{ticket.updated_at}}","ticket_id":"{{ticket.id}}","status":"{{ticket.status}}","subject":"{{ticket.title}}"}`. Ajustar escaping con las funciones oficiales de Zendesk y probar el JSON. La combinación ID+updated_at diferencia actualizaciones, pero varias reglas sobre el mismo cambio colapsarán deliberadamente; usar reglas/event_id propios si se necesitan eventos distintos. No usar invocation ID no firmado como única identidad.

Brevo `event` es el **nombre del payload**, que puede diferir del nombre al registrar: email enviado se recibe `request`, email inválido `invalid_email`; marketing baja `unsubscribe`, transaccional baja `unsubscribed`. Transaccionales admitidos: request, delivered, hard_bounce, soft_bounce, blocked, spam, invalid_email, deferred, click, opened, unique_opened, unsubscribed, error, proxy_open, unique_proxy_open. Marketing: delivered, opened, click, hard_bounce, soft_bounce, spam, unsubscribe, list_addition, contact_updated, contact_deleted, proxy_open. Solo se entregan los objetos del evento configurado, incluidos lotes. Las claves internas `key`, cuerpos de email y mirror links no se exponen.

## Límites de eventos y alternativas

Esta implementación cubre los eventos de la tabla, no todos los catálogos posibles de cada proveedor. En particular, Telegram ignora edited_message, channel_post, callbacks y otros Updates; WhatsApp entrega texto y referencias multimedia pero no flujos/interactive completos; Typeform no dispara parciales; Slack no incluye interactive/slash commands, bots ni reacciones como eventos equivalentes. Pueden consultarse detalles con sus nodos de acción cuando exista la operación. Las notificaciones no sustituyen consultas exhaustivas ni histórico.

PayPal, TransactionalEmail (Resend/SendGrid), Mailchimp y Discord se auditan en la matriz general y adaptador de negocio separado; no están duplicados en esta clase. Discord Gateway requiere conexión residente para eventos de mensajes; sus eventos HTTP de aplicación no son equivalentes. Mailchimp Marketing incorpora firmas en la documentación vigente; el adaptador de negocio separado exige ese protocolo y rechaza las entregas antiguas sin firma. Suscripciones Google/Microsoft con caducidad quedan en la estrategia polling del inventario general.

## Fuentes oficiales consultadas

- [GitHub: validación de entregas](https://docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries).
- [WooCommerce: configuración](https://developer.woocommerce.com/docs/best-practices/urls-and-routing/webhooks/) y [código del protocolo/ping](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-webhook.html).
- [ClickUp: webhooks](https://developer.clickup.com/docs/webhooks) y [firma](https://developer.clickup.com/docs/webhooksignature).
- [Linear: eventos y firma](https://linear.app/developers/webhooks).
- [Intercom v2.17: topics, permisos y firma](https://developers.intercom.com/docs/references/webhooks/webhook-models). Los antiguos `contact.created`/`user.created` cambiaron a `contact.lead.created`/`contact.user.created`; esta implementación selecciona nombres vigentes.
- [Zendesk: autenticidad](https://developer.zendesk.com/documentation/webhooks/verifying/) y [API de webhooks](https://developer.zendesk.com/api-reference/webhooks/webhooks-api/webhooks/).
- [Typeform: seguridad](https://www.typeform.com/developers/webhooks/secure-your-webhooks/) y [registro](https://www.typeform.com/developers/webhooks/reference/create-or-update-webhook/).
- [Slack: firma](https://docs.slack.dev/authentication/verifying-requests-from-slack/).
- [Telegram: setWebhook](https://core.telegram.org/bots/api#setwebhook).
- [Meta: registro/verificación](https://developers.facebook.com/docs/graph-api/webhooks/getting-started/) y [SDK oficial Meta, protocolo](https://whatsapp.github.io/WhatsApp-Nodejs-SDK/api-reference/webhooks/start/) (SDK archivado, usado como corroboración del protocolo, no dependencia).
- [Twilio: seguridad](https://www.twilio.com/docs/usage/webhooks/webhooks-security).
- [Brevo: autenticación](https://developers.brevo.com/docs/secured-webhooks), [payloads transaccionales](https://developers.brevo.com/docs/transactional-webhooks), [payloads marketing](https://developers.brevo.com/docs/marketing-webhooks).
- [Trello: registro, firma y reintentos](https://developer.atlassian.com/cloud/trello/guides/rest-api/webhooks/).
- [Mailchimp: firmas de audiencias](https://mailchimp.com/developer/marketing/api-concepts/webhooks/#verifying-webhook-signatures), [Discord: webhooks HTTP oficiales](https://github.com/discord/discord-api-docs/blob/main/developers/events/webhook-events.mdx), [PayPal: verificación](https://developer.paypal.com/api/webhooks/overview/).

## Verificación

Aprobadas: `php tests/triggers/webhooks.php` (256 aserciones, 32 definiciones, HTTP/DB no usados), `php -l src/Core/TriggerProviders/WebhookProviders.php` y `php -l tests/triggers/webhooks.php`. Cubren firmas válidas/falsas, bytes alterados, timestamps vencidos, aislamiento de scope, IDs repetibles, handshakes, JSON Unicode/form y filtrado de secretos conocidos. La deduplicación transaccional, cola y ejecución posterior se prueban por el coordinador en las pruebas del servicio compartido.

No ejecutadas: URL pública real, firma generada por proveedor real, registro/baja remota y permisos reales; requieren despliegue/configuración y autorización adicional para modificar cuentas externas. No hay pruebas focalizadas fallidas pendientes.
