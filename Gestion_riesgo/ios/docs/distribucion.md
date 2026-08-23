# Cómo llega la aplicación a un iPhone, sin pagar los 99 USD

En Android el APK se pasa de teléfono a teléfono por Bluetooth. **En iOS eso no
existe**: Apple no permite instalar nada que no venga de un canal firmado por
ellos. Esto recoge lo que sí hay.

## La respuesta corta

**La Alcaldía puede tener el programa gratis.** Apple exime del pago a las
entidades de gobierno, y una alcaldía municipal lo es. Es la opción buena y hay
que agotarla antes que cualquier otra.

---

## 1 · Exención de pago para entidades de gobierno — **lo primero que hay que intentar**

Apple concede la membresía sin costo a organizaciones sin ánimo de lucro,
instituciones educativas acreditadas y **entidades de gobierno**.

Requisitos, y la Alcaldía los cumple todos:

| Requisito | La Alcaldía |
|---|---|
| Ser una entidad legal, no una persona | Sí |
| No haber firmado el acuerdo de aplicaciones de pago | Sí, la aplicación es gratuita |
| No vender bienes ni servicios digitales en la aplicación | Sí |

Se pide **al inscribirse**, marcando la casilla de exención. Si ya se pagó, no
hay devolución — otra razón para pedirlo desde el principio.

Hace falta el D-U-N-S de la entidad y que quien lo tramite tenga poder para
firmar en nombre de la Alcaldía.

**Lo que no pude confirmar:** la página de Apple no publica la lista de países
donde aplica la exención. Menciona ejemplos de Estados Unidos, la Unión Europea
y China, y **no dice nada de Colombia**. Hay que preguntarlo directamente antes
de dar por hecho que sí.

Con la exención concedida se abren TestFlight y App Store completos, que es
exactamente lo mismo que con los 99 USD pagados.

---

## 2 · Aplicación web instalable — **gratis, hoy, sin trámite**

El sitio ya existe: `grj.oticjamundi.com/preinscripcion`. En un iPhone se abre en
Safari, se toca «Compartir → Añadir a pantalla de inicio» y queda con su icono
como cualquier aplicación.

Qué se conserva y qué no, frente al APK:

| | Aplicación web en iPhone |
|---|---|
| Costo y trámite | Ninguno |
| Se instala sin conexión | **No.** Hace falta abrir el sitio una vez |
| Funciona sin señal después | Sí, con el Service Worker que ya existe |
| Cámara y galería | Sí, con `<input type="file">` |
| **Grabar video** | ⚠ **Hay que probarlo.** Ver abajo |
| Envío automático en segundo plano | No — igual que la aplicación nativa en iOS |

### El aviso sobre la cámara

En los foros de Apple hay reportes de que **`getUserMedia` deja de funcionar
cuando la web se abre desde el icono de la pantalla de inicio** —en modo
standalone— aunque funcione bien desde Safari. Se reportó arreglado en iOS 17 y
vuelto a romper en iOS 18.

No es concluyente y puede estar resuelto. **Pero hay que probarlo en un iPhone
real antes de prometerle a nadie que el video funciona por esta vía.** Las fotos
usan `<input type="file">`, que es otro camino y no está afectado.

---

## 3 · Firma libre con Apple ID gratuito — **no sirve para ciudadanos**

Se puede firmar una aplicación con una cuenta gratuita desde Xcode. Los límites
lo descartan para este caso:

- **Caduca a los 7 días.** Después hay que volver a firmarla e instalarla.
- Tres aplicaciones por aparato, tres aparatos, diez identificadores por semana.
- Hace falta un Mac con Xcode y el iPhone conectado por cable.

Sirve para que el equipo pruebe. Pedirle a una familia damnificada que traiga el
teléfono cada semana a la Alcaldía no es una opción.

---

## 4 · Ad Hoc — exige el programa de todas formas

Permite instalar en hasta 100 aparatos registrando el identificador único de
cada uno. **Requiere la membresía**, así que solo tiene sentido si ya se tiene
—con exención o pagada—, y aun así hay que registrar iPhone por iPhone.

Sirve para una brigada de funcionarios. No para la ciudadanía.

---

## 5 · Lo que NO aplica

- **Programa Enterprise (299 USD).** Es para aplicaciones internas de empleados
  de la propia organización. Distribuirla a la ciudadanía con eso viola el
  acuerdo y Apple revoca el certificado — dejando la aplicación muerta en todos
  los teléfonos a la vez.
- **Tiendas alternativas.** Solo en la Unión Europea.
- **AltStore y similares.** Usan la firma gratuita de 7 días por debajo, con sus
  mismos límites.

---

## Recomendación

1. **Pedir la exención de gobierno.** Es gratis, da acceso completo y es lo que
   corresponde a una entidad pública. Preguntar primero si aplica en Colombia.
2. **Mientras tanto, la aplicación web instalable**, que ya está publicada y no
   cuesta nada. Probando antes el video en un iPhone real.
3. Si la exención no se concede, **pagar los 99 USD tiene sentido igual**: es
   menos de lo que cuesta una jornada de brigada, y sin eso no hay forma de
   llegar a un iPhone.

## Una cosa que conviene tener presente

La razón de fondo del APK era instalarlo **donde no hay señal**, pasándolo entre
teléfonos. En iOS eso no se puede por ninguna de estas vías: siempre hace falta
internet para instalar.

Así que en iPhone la ventaja del APK sobre la aplicación web se reduce mucho — y
lo que queda es el envío en segundo plano, que en iOS tampoco está garantizado
(ver [`sincronizacion.md`](sincronizacion.md)).

**Antes de invertir en iOS nativo, vale la pena medir cuántos de los afectados
usan iPhone.** En zona rural colombiana suelen ser pocos, y la aplicación web
podría bastar.

## Fuentes

- [Apple Developer Program Fee Waiver](https://developer.apple.com/help/account/membership/fee-waivers/)
- [Choosing a Membership](https://developer.apple.com/support/compare-memberships/)
- [Free provisioning: límites de 7 días](https://developer.apple.com/forums/thread/669516)
- [Camera does not open from iOS PWA](https://developer.apple.com/forums/thread/129428)
- [Workers at Your Service — WebKit](https://webkit.org/blog/8090/workers-at-your-service/)
