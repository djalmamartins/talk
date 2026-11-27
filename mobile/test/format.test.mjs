// Testes das funções puras do app (rodar: npm test). As telas são conferidas no aparelho (docs/app-movel.md).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { displayName, initials, listTime, plain, waitLabel } from '../src/lib/format.js';

test('hora da lista como no app de mensagens', () => {
  const now = new Date(2026, 10, 27, 15, 0);
  assert.equal(listTime('2026-11-27 10:41:00', now), '10:41');
  assert.equal(listTime('2026-11-26 22:00:00', now), 'Ontem');
  assert.equal(listTime('2026-11-22 09:00:00', now), 'domingo');
  assert.equal(listTime('2026-11-20 09:00:00', now), '20/11/2026');
  assert.equal(listTime(null, now), '');
});

test('tempo de espera na fila', () => {
  assert.equal(waitLabel(20), 'agora');
  assert.equal(waitLabel(240), '4 min');
  assert.equal(waitLabel(3900), '1 h 05');
});

test('nome, iniciais e prévia sem a marcação do WhatsApp', () => {
  assert.equal(displayName('', '5531988093555'), '+5531988093555');
  assert.equal(displayName('  Carla Ferreira ', ''), 'Carla Ferreira');
  assert.equal(initials('Carla de Souza Ferreira'), 'CF');
  assert.equal(initials('', '5531999990042'), '42');
  assert.equal(plain('*Boleto* de _novembro_\n pago'), 'Boleto de novembro pago');
});
