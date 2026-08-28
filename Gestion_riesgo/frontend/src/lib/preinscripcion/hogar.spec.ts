import { describe, expect, it } from 'vitest';
import {
	desdeCenso,
	estaCorregida,
	personaVacia,
	personasParaEnviar,
	sePuedeQuitar,
	type PersonaCenso
} from './hogar';

function delCenso(extra: Partial<PersonaCenso> = {}): PersonaCenso {
	return {
		id: 7,
		nombres: 'Martha Cecilia',
		apellidos: 'Londoño Zaen',
		tipo_documento: 1,
		numero_documento: '16844290',
		parentesco: 1,
		genero: 2,
		fecha_nacimiento: '1970-05-02',
		...extra
	};
}

describe('el hogar que trae el censo', () => {
	it('cada persona conserva de quién salió', () => {
		// Sin esto, al enviar no se puede distinguir a quien ya estaba de quien
		// la familia agregó, y todo entraría como persona nueva.
		const [p] = desdeCenso([delCenso()]);

		expect(p.rufe_persona_id).toBe(7);
		expect(p.no_vive_aqui).toBe(false);
	});

	it('a quien vino del censo no se le puede quitar de la lista', () => {
		// Se marca «ya no vive aquí», que es una afirmación que un funcionario
		// revisa. Borrarla perdería que esa persona estuvo alguna vez.
		const [delRufe] = desdeCenso([delCenso()]);

		expect(sePuedeQuitar(delRufe)).toBe(false);
		expect(sePuedeQuitar(personaVacia())).toBe(true);
	});

	it('cambiar un apellido se ve como corrección', () => {
		const censo = [delCenso()];
		const [p] = desdeCenso(censo);
		p.apellidos = 'Londoño Zaén';

		expect(estaCorregida(p, censo)).toBe(true);
	});

	it('mayúsculas y espacios de sobra no son una corrección', () => {
		// Si lo fueran, la bandeja se llenaría de avisos que no cambian nada y
		// el funcionario dejaría de mirarlos, incluidos los de verdad.
		const censo = [delCenso()];
		const [p] = desdeCenso(censo);
		p.nombres = '  MARTHA   CECILIA ';

		expect(estaCorregida(p, censo)).toBe(false);
	});

	it('una persona agregada por la familia nunca es «corregida»', () => {
		// No hay nada con qué compararla: es nueva, y así tiene que verse.
		expect(estaCorregida(personaVacia(), [delCenso()])).toBe(false);
	});

	it('las filas vacías no viajan al servidor', () => {
		// Quedan de pulsar «Agregar otra persona» y arrepentirse.
		const enviadas = personasParaEnviar([personaVacia(), ...desdeCenso([delCenso()])]);

		expect(enviadas).toHaveLength(1);
	});

	it('lo que viaja no lleva el identificador de pantalla', () => {
		// `uid` solo existe para que el {#each} no se confunda. Mandarlo sería
		// mandar basura que el servidor tendría que ignorar.
		const [enviada] = personasParaEnviar(desdeCenso([delCenso()]));

		expect(enviada).not.toHaveProperty('uid');
		expect(enviada.rufe_persona_id).toBe(7);
	});

	it('quien ya no vive ahí sí viaja, marcado', () => {
		// Es la diferencia entre «corrija esto» y «esta persona desapareció sin
		// que nadie lo sepa».
		const personas = desdeCenso([delCenso()]);
		personas[0].no_vive_aqui = true;

		const [enviada] = personasParaEnviar(personas);

		expect(enviada.no_vive_aqui).toBe(true);
	});
});
