// Los tipos que describen las cifras del tablero.
//
// Este archivo era el respaldo estático: una foto del RUFE tomada de la hoja de
// Google que se dibujaba mientras cargaba lo de verdad y también cuando la hoja
// fallaba. Se retiró con el resto del puente a Google — un tablero que enseña
// cifras de hace diez días sin decirlo es peor que uno que dice «no pude
// cargar», porque nadie sospecha de un número que se ve bien.
//
// Queda solo la reexportación de tipos, que es lo que media docena de
// componentes ya importaba de aquí. La forma no cambió: `GET /rufe/tablero`
// devuelve exactamente la misma que producía la hoja, y por eso la interfaz del
// tablero no tuvo que tocarse.

export type { Zona, Barrio, Hogar, Dataset } from './rufe/types';
