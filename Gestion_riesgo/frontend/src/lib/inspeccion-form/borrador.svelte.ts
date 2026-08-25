// Autoguardado de las inspecciones en curso.
//
// Una inspección se llena de pie en la puerta de una casa y lleva un rato: la
// tabla del 5.4 sola son siete elementos con su nivel. Perderla por una llamada
// entrante, una batería que se apaga o un toque en «atrás» significa repetir la
// visita, y esa visita cuesta un desplazamiento a una vereda.
//
// ── Por qué son VARIOS y no uno ──────────────────────────────────────────────
//
// Antes solo cabía uno. Es lo que hace una brigada en una mañana: entra a una
// casa, la deja a medias porque falta hablar con el propietario, y sigue a la
// de al lado. Con un único borrador, empezar la segunda pisaba la primera —y la
// pantalla decía «Hay una inspección sin terminar» sin decir de quién, así que
// la única forma de saber qué se estaba a punto de perder era abrirla.
//
// Ahora se guardan por separado y cada una se reconoce por el nombre del
// propietario y la dirección. Esos dos campos son del numeral 3, uno de los
// primeros que se llena, así que casi siempre hay con qué nombrarlas.
//
// Es un módulo propio y no el del RUFE porque aquel está atado a la forma del
// formulario del censo. Comparten las decisiones —clave propia, caducidad,
// escritura con retardo— pero no el código: forzar un genérico sobre el del
// RUFE tocaría un archivo que hoy funciona y que guarda datos de hogares.

import { browser } from "$app/environment";
import type { FormularioInspeccion } from "./tipos";
import type { IdPaso } from "./esquema";

export const CLAVE_ALMACEN = "sgr_inspeccion_borradores_v2";

/** La caja de un solo borrador, anterior a esto. Se adopta y se retira. */
export const CLAVE_ALMACEN_V1 = "sgr_inspeccion_borrador_v1";

const VERSION = 2;
const DIAS_VIGENCIA = 7;
const RETARDO_MS = 800;

export type EstadoGuardado =
  "sin-cambios" | "guardando" | "guardado" | "error" | "recuperado";

export type BorradorGuardado = {
  version: number;
  clave: string;
  actualizado_en: number;
  expira_en: number;
  paso: IdPaso;
  datos: FormularioInspeccion;
};

export function uid(): string {
  if (
    typeof crypto !== "undefined" &&
    typeof crypto.randomUUID === "function"
  ) {
    return crypto.randomUUID();
  }

  return `id-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

function leerCrudo(clave: string): string | null {
  if (!browser) return null;

  try {
    return window.localStorage.getItem(clave);
  } catch {
    return null;
  }
}

function escribir(lista: BorradorGuardado[]): boolean {
  if (!browser) return false;

  try {
    window.localStorage.setItem(CLAVE_ALMACEN, JSON.stringify(lista));

    return true;
  } catch {
    return false;
  }
}

function vigente(b: unknown, ahora: number): b is BorradorGuardado {
  const c = b as BorradorGuardado;

  return (
    !!c &&
    typeof c === "object" &&
    // Una versión anterior del formato tendría campos que ya no existen;
    // recuperarla dejaría la pantalla a medio pintar sin decir por qué.
    c.version === VERSION &&
    typeof c.clave === "string" &&
    c.clave !== "" &&
    // Caducado: una inspección de hace más de una semana no se retoma, se
    // vuelve a hacer. Los daños de una vivienda cambian.
    typeof c.expira_en === "number" &&
    c.expira_en > ahora
  );
}

/**
 * Lo que quedó guardado del formato de un solo borrador.
 *
 * Se adopta en vez de descartarse: en el momento del cambio puede haber
 * censadores con una inspección a medias en el teléfono, y perderla es la misma
 * visita repetida que todo este módulo existe para evitar.
 */
function adoptarLaVieja(ahora: number): BorradorGuardado[] {
  const crudo = leerCrudo(CLAVE_ALMACEN_V1);
  if (!crudo) return [];

  try {
    const b = JSON.parse(crudo) as BorradorGuardado;

    if (
      typeof b.clave !== "string" ||
      typeof b.expira_en !== "number" ||
      b.expira_en <= ahora
    ) {
      return [];
    }

    return [{ ...b, version: VERSION }];
  } catch {
    return [];
  } finally {
    try {
      window.localStorage.removeItem(CLAVE_ALMACEN_V1);
    } catch {
      /* si no se puede borrar, caducará solo */
    }
  }
}

/**
 * Los borradores vigentes, del más reciente al más antiguo.
 *
 * Purga de paso lo caducado: leer es la única operación que ocurre siempre, así
 * que es el sitio donde la limpieza no depende de que alguien pase por una
 * pantalla concreta.
 */
export function leerBorradores(ahora = Date.now()): BorradorGuardado[] {
  if (!browser) return [];

  const crudo = leerCrudo(CLAVE_ALMACEN);
  let lista: BorradorGuardado[] = [];

  if (crudo) {
    try {
      const leido = JSON.parse(crudo);
      lista = Array.isArray(leido)
        ? leido.filter((b) => vigente(b, ahora))
        : [];
    } catch {
      lista = [];
    }
  } else {
    lista = adoptarLaVieja(ahora);
    if (lista.length > 0) escribir(lista);
  }

  return lista.sort((a, b) => b.actualizado_en - a.actualizado_en);
}

/** Uno concreto, o `null` si ya no está o caducó. */
export function leerBorrador(
  clave: string,
  ahora = Date.now(),
): BorradorGuardado | null {
  return leerBorradores(ahora).find((b) => b.clave === clave) ?? null;
}

/** Guarda o reemplaza. Devuelve `false` si el navegador no dejó escribir. */
export function guardarBorrador(
  b: BorradorGuardado,
  ahora = Date.now(),
): boolean {
  const resto = leerBorradores(ahora).filter((x) => x.clave !== b.clave);

  return escribir([b, ...resto]);
}

/**
 * Descarta uno.
 *
 * NO borra sus fotos: viven en IndexedDB atadas a la misma clave y quien llama
 * tiene que encargarse. Se deja aquí escrito porque olvidarlo deja megabytes de
 * fotos de casas ajenas en un teléfono que se presta.
 */
export function descartarBorrador(clave: string): void {
  if (!browser) return;

  escribir(leerBorradores().filter((b) => b.clave !== clave));
}

// ── Cómo se reconoce cada uno ────────────────────────────────────────────────

export type SenasBorrador = {
  /** A nombre de quién. Es lo que se lee primero. */
  titulo: string;
  /** Dónde queda. Distingue dos casas del mismo apellido. */
  lugar: string;
  /** `true` cuando aún no hay nada con qué nombrarla. */
  anonima: boolean;
};

/**
 * Con qué nombre aparece un borrador en la lista.
 *
 * El propietario y la dirección son del numeral 3, de los primeros que se
 * llenan, así que casi siempre hay algo. Cuando no lo hay se dice —«Sin datos
 * del propietario todavía»— en vez de inventar un nombre: quien tiene que
 * decidir si la descarta necesita saber que no puede identificarla, no una
 * etiqueta que parezca un dato.
 */
export function senasDe(b: BorradorGuardado): SenasBorrador {
  const d = b.datos;
  const nombre = (d?.propietario_nombres ?? "").trim();

  const lugar =
    (d?.direccion_cabecera ?? "").trim() ||
    [(d?.corregimiento ?? "").trim(), (d?.vereda ?? "").trim()]
      .filter(Boolean)
      .join(" · ");

  return {
    titulo: nombre || "Sin datos del propietario todavía",
    lugar,
    anonima: nombre === "",
  };
}

/**
 * Cuánto hace que se tocó, en palabras.
 *
 * Con la hora exacta no basta: «11:40 a. m.» no dice si fue hoy. Y quien mira
 * esta lista está decidiendo cuál retomar, que es una pregunta sobre hace
 * cuánto, no sobre qué hora era.
 */
export function haceCuanto(cuando: number, ahora = Date.now()): string {
  const minutos = Math.round((ahora - cuando) / 60_000);

  if (minutos < 1) return "hace un momento";
  if (minutos < 60) return `hace ${minutos} min`;

  const horas = Math.round(minutos / 60);
  if (horas < 24) return horas === 1 ? "hace 1 hora" : `hace ${horas} horas`;

  const dias = Math.round(horas / 24);
  if (dias === 1) return "ayer";

  return `hace ${dias} días`;
}

/** Cuándo deja de poder retomarse, para poder avisarlo antes de que pase. */
export function diasQueLeQuedan(
  b: BorradorGuardado,
  ahora = Date.now(),
): number {
  return Math.max(0, Math.ceil((b.expira_en - ahora) / 86400_000));
}

export class GestorBorrador {
  estado = $state<EstadoGuardado>("sin-cambios");
  clave = $state<string>("");
  guardadoEn = $state<number | null>(null);

  #temporizador: ReturnType<typeof setTimeout> | null = null;

  constructor(clave?: string) {
    this.clave = clave ?? uid();
  }

  marcarRecuperado(cuando: number): void {
    this.estado = "recuperado";
    this.guardadoEn = cuando;
  }

  /**
   * Programa el guardado.
   *
   * Con retardo y no en cada tecla: escribir en localStorage es síncrono y
   * hacerlo en cada pulsación se nota en un teléfono de gama baja justo cuando
   * alguien está escribiendo una dirección larga.
   */
  programar(datos: FormularioInspeccion, paso: IdPaso): void {
    if (!browser) return;

    this.estado = "guardando";

    if (this.#temporizador) clearTimeout(this.#temporizador);
    this.#temporizador = setTimeout(
      () => this.guardar(datos, paso),
      RETARDO_MS,
    );
  }

  guardar(datos: FormularioInspeccion, paso: IdPaso): void {
    if (!browser) return;

    const ahora = Date.now();

    const ok = guardarBorrador(
      {
        version: VERSION,
        clave: this.clave,
        actualizado_en: ahora,
        expira_en: ahora + DIAS_VIGENCIA * 86400_000,
        paso,
        datos: $state.snapshot(datos),
      },
      ahora,
    );

    if (ok) {
      this.estado = "guardado";
      this.guardadoEn = ahora;
    } else {
      // Sin espacio o con almacenamiento bloqueado. Se avisa en pantalla: es
      // la diferencia entre saber que hay que terminar de una sentada y
      // creerse a salvo.
      this.estado = "error";
    }
  }

  detener(): void {
    if (this.#temporizador) clearTimeout(this.#temporizador);
    this.#temporizador = null;
  }
}

export function describirEstado(
  estado: EstadoGuardado,
  guardadoEn: number | null,
): string {
  switch (estado) {
    case "guardando":
      return "Guardando…";
    case "guardado":
      return guardadoEn
        ? `Guardado a las ${new Date(guardadoEn).toLocaleTimeString("es-CO", {
            hour: "2-digit",
            minute: "2-digit",
          })}`
        : "Guardado";
    case "recuperado":
      return "Se recuperó una inspección sin terminar.";
    case "error":
      return "No se pudo guardar en este dispositivo. Termine sin cerrar la aplicación.";
    default:
      return "";
  }
}
