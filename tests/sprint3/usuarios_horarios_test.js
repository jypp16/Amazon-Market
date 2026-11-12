const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const serviceSource = fs.readFileSync('Assets/js/usuarios-horarios-mock.js', 'utf8');
const screenSource = fs.readFileSync('Assets/js/usuarios.js', 'utf8');
const viewSource = fs.readFileSync('Views/Usuario/index.php', 'utf8');

function createService() {
    const context = {};
    vm.createContext(context);
    vm.runInContext(serviceSource, context);
    return vm.runInContext('UsuariosHorariosService', context);
}

async function main() {
    const service = createService();
    const week1 = '2026-11-09';
    const week2 = '2026-11-16';
    const week3 = '2026-11-23';

    await service.guardarProgramacion(90, week1, {
        turnoId: 1, dias: [0, 1, 2, 3, 4], alcance: 'desde'
    });
    const firstWeek = await service.getProgramacion(90, week1);
    const followingWeek = await service.getProgramacion(90, week2);
    assert.equal(firstWeek.alcance, 'desde');
    assert.deepEqual(Array.from(followingWeek.dias), [0, 1, 2, 3, 4]);
    assert.equal(followingWeek.turnoId, 1);
    assert.equal(followingWeek.semanaInicio, week2);
    console.log('[PASS] La primera programación queda vigente en la semana siguiente.');

    await service.guardarProgramacion(91, week1, {
        turnoId: 1, dias: [0, 1, 2, 3, 4], alcance: 'desde'
    });
    await service.guardarProgramacion(91, week2, {
        turnoId: 2, dias: [1, 2, 3, 4, 5], alcance: 'semana'
    });
    const exception = await service.getProgramacion(91, week2);
    const reverted = await service.getProgramacion(91, week3);
    assert.equal(exception.turnoId, 2);
    assert.equal(exception.alcance, 'semana');
    assert.equal(reverted.turnoId, 1);
    assert.deepEqual(Array.from(reverted.dias), [0, 1, 2, 3, 4]);
    assert.equal(reverted.alcance, 'desde');
    console.log('[PASS] La excepción semanal termina y la semana siguiente vuelve a la base.');

    await service.guardarProgramacion(91, week2, {
        turnoId: 2, dias: [0, 1, 2, 3, 4, 5], alcance: 'desde'
    });
    const updatedBase = await service.getProgramacion(91, week3);
    assert.equal(updatedBase.turnoId, 2);
    assert.deepEqual(Array.from(updatedBase.dias), [0, 1, 2, 3, 4, 5]);
    console.log('[PASS] Cambiar el alcance a “desde” actualiza la base futura.');

    assert.match(screenSource, /: 'desde'/);
    assert.match(viewSource, /program_base_hint/);
    assert.match(viewSource, /Esta será la programación base/);
    console.log('[PASS] El modal establece la primera programación como base y lo informa al administrador.');
}

main().catch(error => {
    console.error('[FAIL] ' + error.stack);
    process.exitCode = 1;
});
