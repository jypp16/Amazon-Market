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
        turnos: [{ turnoId: 1, dias: [0, 1, 2, 3, 4] }], alcance: 'desde'
    });
    const firstWeek = await service.getProgramacion(90, week1);
    const followingWeek = await service.getProgramacion(90, week2);
    assert.equal(firstWeek.alcance, 'desde');
    assert.deepEqual(JSON.parse(JSON.stringify(followingWeek.turnos)), [
        { turnoId: 1, dias: [0, 1, 2, 3, 4] }
    ]);
    assert.equal(followingWeek.semanaInicio, week2);
    console.log('[PASS] La primera programación queda vigente en la semana siguiente.');

    await service.guardarProgramacion(91, week1, {
        turnos: [{ turnoId: 1, dias: [0, 1, 2, 3, 4] }], alcance: 'desde'
    });
    await service.guardarProgramacion(91, week2, {
        turnos: [{ turnoId: 2, dias: [1, 2, 3, 4, 5] }], alcance: 'semana'
    });
    const exception = await service.getProgramacion(91, week2);
    const reverted = await service.getProgramacion(91, week3);
    assert.deepEqual(JSON.parse(JSON.stringify(exception.turnos)), [
        { turnoId: 2, dias: [1, 2, 3, 4, 5] }
    ]);
    assert.equal(exception.alcance, 'semana');
    assert.deepEqual(JSON.parse(JSON.stringify(reverted.turnos)), [
        { turnoId: 1, dias: [0, 1, 2, 3, 4] }
    ]);
    assert.equal(reverted.alcance, 'desde');
    console.log('[PASS] La excepción semanal termina y la semana siguiente vuelve a la base.');

    await service.guardarProgramacion(91, week2, {
        turnos: [
            { turnoId: 1, dias: [0, 1, 2, 3, 4] },
            { turnoId: 2, dias: [0, 5, 6] }
        ],
        alcance: 'desde'
    });
    const updatedBase = await service.getProgramacion(91, week3);
    assert.deepEqual(JSON.parse(JSON.stringify(updatedBase.turnos)), [
        { turnoId: 1, dias: [0, 1, 2, 3, 4] },
        { turnoId: 2, dias: [0, 5, 6] }
    ]);
    assert.ok(updatedBase.turnos.every(horario => horario.dias.includes(0)));
    console.log('[PASS] La base futura permite Mañana y Tarde el mismo lunes.');

    await assert.rejects(
        service.guardarProgramacion(92, week1, { turnos: [], alcance: 'desde' }),
        /Selecciona al menos un turno/
    );
    await assert.rejects(
        service.guardarProgramacion(92, week1, {
            turnos: [
                { turnoId: 1, dias: [0] },
                { turnoId: 1, dias: [1] }
            ],
            alcance: 'desde'
        }),
        /No repitas un turno/
    );
    console.log('[PASS] La validación exige turnos y evita duplicarlos.');

    assert.match(screenSource, /: 'desde'/);
    assert.match(screenSource, /turnosSeleccionados/);
    assert.match(screenSource, /program_turnos\[\]/);
    assert.match(screenSource, /user-schedule-shift-days/);
    assert.match(viewSource, /program_base_hint/);
    assert.match(viewSource, /Esta será la programación base/);
    assert.match(viewSource, /Cada turno tiene sus propios días/);
    console.log('[PASS] El modal permite configurar días independientes por turno.');
}

main().catch(error => {
    console.error('[FAIL] ' + error.stack);
    process.exitCode = 1;
});
