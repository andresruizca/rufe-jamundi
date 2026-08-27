# Plan: conciliar el Dashboard con los datos oficiales

*26 de agosto de 2026. Escrito ANTES de tocar nada, a pedido de Andrés.*

## El problema en una línea

Desde hoy la base MySQL tiene **1.380 fichas RUFE oficiales**, pero el Dashboard
—y la sección Mapas— siguen leyendo **hojas de Google en vivo**. Son dos censos
distintos del mismo sismo, y hoy nadie sabe cuál está mirando.

## De dónde lee cada cosa HOY

| Pantalla | Fuente | Ruta en el código |
|---|---|---|
| `/dashboard` · pestaña Personas | Hoja de Google «RUFE» + «BASE-DATOS RUFE», en vivo desde el navegador | `lib/rufe/live.ts`, `lib/rufe/source.ts` |
| `/dashboard` · Instituciones educativas | Otra pestaña de la misma hoja | `lib/instEducativas/source.ts` |
| `/dashboard` · Equipamientos | Otra pestaña de la misma hoja | `lib/equipamientos/source.ts` |
| `/riesgo/mapas` | **La misma hoja de Google** | `routes/riesgo/mapas/+page.svelte:18` |
| Respaldo cuando la hoja falla | Foto estática del 18 de agosto | `lib/data/rufe-fallback.json` |
| `/riesgo/reportes`, call center, inspecciones | **MySQL** (lo oficial) | la API |

La hoja está compartida como «cualquiera con el enlace puede ver». Es lo que
permite leerla desde el navegador sin backend — y también significa que el
censo de damnificados de Jamundí es hoy **públicamente descargable** por quien
tenga la URL.

## La medición, número por número

Comparación entre la foto de la hoja (18 ago) y la base oficial de hoy:

| | Hoja de Google | Base oficial | |
|---|---|---|---|
| Personas | 2.856 | 2.837 | ≈ iguales |
| Hogares | 1.355 | 1.380 | ≈ iguales |
| **Barrios distintos** | **117** | **249** | ⚠ se duplican |
| Con género | 97 % | 99 % | ≈ |
| **Con edad** | **60 %** (1.730) | **30 %** (853) | ⚠ se pierde la mitad |
| Hombres / Mujeres | 1.135 / 1.653 | 1.171 / 1.626 | ≈ |
| **Evacuados «SI»** | **73** | **1** | ⚠ se pierde |
| **Visitas «SI»** | **169** | **0** | ⚠ se pierde |

Que personas y hogares casi coincidan confirma lo importante: **son el mismo
censo digitalizado dos veces**, no dos poblaciones distintas. Lo que cambia es
qué campos sobrevivieron a cada digitalización.

## Los cuatro hallazgos que obligan a decidir

### 1. Los barrios se duplican: 117 → 249

El RUD trae vereda/barrio como texto libre y sin normalizar. En la base ya
conviven «Bocas Del Palo» y «Bocas del Palo», «Colinas De Miravalle» y «Colinas
De Miravalle 3». Si el tablero pasa a la base tal cual, la tabla de barrios
pasa de 117 filas a 249 y **parte barrios reales en varios**, con lo que ningún
total por barrio será confiable.

Esto no es cosmético: la tabla por barrio es lo que se usa para decidir a dónde
va una brigada.

### 2. La edad cubre la mitad: 60 % → 30 %

Los cuatro indicadores de edad —Niños, Jóvenes, Adultos, Adultos mayores— hoy
se calculan sobre 1.730 personas. Con la base oficial se calcularían sobre 853.
El tablero seguiría dibujándolos, pero contarían **la mitad de la gente**, sin
que nada en pantalla lo advierta.

### 3. Evacuados y visitas desaparecen

El RUD no trae ninguna de las dos cosas. La hoja sí: 73 hogares evacuados y 169
visitas hechas, con el nombre de quién visitó. Pasar el tablero a la base sin
más deja esos dos indicadores en cero, y **cero no significa «ninguno», significa
«no lo sabemos»** — que es exactamente la clase de confusión que hace que
alguien reporte mal a la Alcaldía.

### 4. Hay una salida: las dos fuentes comparten la cédula

La hoja de Google trae el número de documento por persona (columna 8). La base
también. Eso permite **cruzarlas por cédula** y recuperar en la base lo que solo
tiene la hoja: fecha de nacimiento, evacuación, visita y el barrio ya escrito
limpio. Es la misma técnica que se usó para conciliar el RUD, y con la misma
regla: lo que no case, va a un informe de revisión, no se adivina.

---

## Decisiones tomadas (Andrés, 26 de agosto)

1. **Se incorpora lo que solo tiene la hoja**, cruzando por cédula: fechas de
   nacimiento, evacuación y visitas. Lo que discrepe va a un informe.
2. **Visita y quién visitó van como dos columnas nuevas en `rufe_reportes`**, no
   como inspecciones. Es más fiel al origen: son una casilla del censo en papel,
   no una inspección técnica con su formato y su profesional a cargo.
3. **Los barrios se normalizan para agrupar**, con informe de qué se fusionó. El
   nombre original que escribió el funcionario **no se sobrescribe**: la
   normalización ocurre al sumar, no en la base.
4. **Instituciones educativas y Equipamientos siguen leyendo la hoja**, con un
   aviso visible de que esa parte no es dato oficial del sistema.

---

## Fases propuestas

### Fase 1 · Normalizar los barrios (sin esto, nada de lo demás sirve)

Una clase `Rufe\Barrios` con la misma forma que `Rufe\Rud`: funciones puras,
probadas. Normaliza mayúsculas, tildes, espacios dobles y «De/Del» para
reconocer que dos textos son el mismo barrio, y deja un mapa de alias explícito
para lo que no se resuelve solo.

Sale un informe de qué se fusionó con qué, para revisar antes de aplicar. Los
nombres originales **no se pierden**: la normalización es para agrupar, no para
sobrescribir lo que escribió el funcionario.

### Fase 2 · Un endpoint de tablero en la API

`GET /rufe/tablero`, con permiso de lectura RUFE, que devuelva exactamente la
misma forma `Dataset` que hoy produce la hoja (`total`, `asOf`, `barrios[]`,
`hogares[]`). Así **la interfaz del tablero no cambia ni una línea**: solo se
cambia de dónde vienen los datos.

Los agregados se calculan en SQL, no en el navegador: hoy el tablero baja el
censo entero al teléfono para sumarlo allí.

### Fase 3 · Conciliar por cédula lo que solo tiene la hoja

Un script `scripts/conciliar-tablero.php`, hermano del del RUD, que cruce la
hoja contra la base por cédula y complete:

- **fecha de nacimiento** donde la base no la tenga (recupera ~880 personas),
- **evacuación** (`alojamiento`),
- **visita y quién visitó** — pendiente decidir dónde se guardan, ver preguntas,
- **barrio limpio**, cuando la hoja lo tenga mejor escrito.

Nunca sobrescribe un dato existente con uno distinto: si la base ya dice algo y
la hoja dice otra cosa, eso **no se resuelve solo**, va al informe de
discrepancias con las dos versiones al lado.

### Fase 4 · El tablero y los mapas pasan a la API

`/dashboard` y `/riesgo/mapas` dejan de llamar a `fetchLiveDataset()`. El
respaldo estático de agosto se retira: un tablero que muestra la foto de hace
diez días sin decirlo es peor que uno que dice «no pude cargar».

### Fase 5 · Las otras dos pestañas

Instituciones educativas y Equipamientos **no existen en MySQL**. Son otro censo
—colegios y equipamientos públicos afectados— que nunca entró al sistema.
Requiere decisión aparte (ver preguntas).

---

## Verificación

1. **Cuadre de personas y hogares**: el tablero desde la API debe dar el mismo
   total que `SELECT COUNT(*)` sobre la base, y ese total debe coincidir con lo
   que muestra `/riesgo/reportes`. Hoy las dos pantallas del mismo sistema dan
   cifras distintas.
2. **Cuadre de barrios**: la suma de personas por barrio debe ser igual al total
   de personas. Con 249 barrios sin normalizar eso se cumple por casualidad;
   después de fusionar hay que volver a comprobarlo.
3. **Antes y después de la conciliación por cédula**: cuántas personas ganaron
   fecha de nacimiento, cuántos hogares ganaron evacuación y visita, y cuántas
   discrepancias quedaron en el informe. La suma tiene que cuadrar.
4. Pruebas nuevas en `tests/run.php` para la normalización de barrios y para el
   agregador del tablero, con el mismo arnés sin Composer.
5. Comprobación en producción de que `/dashboard` ya no hace ninguna petición a
   `docs.google.com`.

## Lo que este plan NO hace

- No borra ni modifica las hojas de Google. Se dejan de leer, no se tocan.
- No inventa datos: todo lo que no case va a un informe para revisión humana.
- No cambia el diseño del tablero. Cambia de dónde saca las cifras.


---

# Ejecutado (26 de agosto de 2026, 21:30)

Las cinco fases están aplicadas en producción.

| | Antes (hoja) | Ahora (base oficial) |
|---|---|---|
| Fuente del tablero y los mapas | Google Sheets, en vivo | `GET /rufe/tablero` |
| Personas | 2.856 | 2.832 |
| Hogares | 1.355 | 1.380 |
| Barrios | 117 | 239 (249 sin agrupar) |
| Cobertura de edad | 60 % | **48 %**, tras recuperar 528 fechas |
| Hogares evacuados | 73 | **54** |
| Fichas con dato de visita | 384 | **373** |

Lo que hizo la conciliación por cédula contra la base de producción:

```
Filas de la hoja:                    2.000
Emparejadas por cédula:                980
De la hoja que no están en la base:    319
  fechas de nacimiento completadas:    528
  hogares que pasan a evacuados:        53
  hogares que ganan dato de visita:    373
  discrepancias (no se tocan):          11
```

Las 11 discrepancias son fechas de nacimiento donde las dos digitalizaciones no
coinciden —por ejemplo 2005-09-28 contra 1990-02-15—. **No se resolvieron
solas**: están en `backend/scripts/discrepancias-tablero.csv` con las dos
versiones al lado.

Las 319 personas de la hoja que no están en la base son gente que aquella
digitalización recogió y el RUD no. Quedan como pendiente: son hogares que
podrían faltar en el censo oficial.

La segunda corrida devolvió cero en las tres columnas: el cruce es idempotente
y se puede repetir sin duplicar nada.

## Lo que quedó sin hacer, y por qué

- **Los barrios bajan de 249 a 239, no a 117.** La diferencia no es ortografía:
  el RUD guarda la *vereda o sector* —«Bellavista Finca La Piscina»— y la hoja
  guarda el *barrio*. 84 de los 112 nombres de la hoja sí encuentran su grupo,
  y cubren el 74 % de los hogares. Agrupar el resto exige decisiones de persona
  («Terranova Sector 1» ¿es Terranova?), que es justo lo que el informe permite.
- **BASE-DATOS RUFE** —la segunda hoja, con 29 pestañas por barrio— no se cruzó.
  No aporta visita ni evacuación; solo fechas de nacimiento adicionales.
- **Instituciones educativas y Equipamientos** siguen leyendo la hoja, ahora
  diciéndolo en pantalla.
