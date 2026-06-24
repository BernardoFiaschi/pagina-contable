/*
 * js/comprobante.js
 * -----------------
 * Cálculo EN VIVO del IVA y el total mientras se carga un comprobante.
 * Antes este script estaba copiado dentro de ventas.php, compras.php,
 * editar_venta.php y editar_compra.php. Ahora vive una sola vez acá y los
 * formularios lo cargan con <script src="/js/comprobante.js">.
 *
 * IMPORTANTE: esto es solo comodidad visual. El IVA y el total que VALEN
 * se recalculan SIEMPRE en el servidor (ver includes/comprobante_funciones.php);
 * acá no se confía para nada que sea definitivo.
 *
 * Espera encontrar en la página:
 *   #detalle            la tabla de alícuotas
 *   #filas              el <tbody> donde van las filas
 *   .alic .grav .iva    selects/inputs de cada fila (alícuota, gravado, iva)
 *   #agregar .quitar    botones para sumar/quitar filas
 *   #nograv #exento #otros   inputs de los otros importes
 *   #total              donde se muestra el total
 */

// Recorre todas las filas, calcula el IVA de cada una y suma el total general.
function recalcular() {
    var total = 0;

    document.querySelectorAll('#filas tr').forEach(function (fila) {
        var alic = parseFloat(fila.querySelector('.alic').value) || 0;  // ej. 21
        var grav = parseFloat(fila.querySelector('.grav').value) || 0;  // ej. 10000
        // IVA = gravado * alícuota / 100, redondeado a 2 decimales.
        // (grav * alic) / 100 con Math.round(... )/100 evita errores de coma flotante.
        var iva = Math.round(grav * alic) / 100;
        fila.querySelector('.iva').value = iva.toFixed(2);
        total += grav + iva;
    });

    // Sumamos los importes que no llevan IVA: no gravado, exento y otros tributos.
    ['nograv', 'exento', 'otros'].forEach(function (id) {
        total += parseFloat(document.getElementById(id).value) || 0;
    });

    // Mostramos el total con formato argentino (1.234,56).
    document.getElementById('total').textContent =
        total.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Botón "+ Agregar alícuota": clona la primera fila, la limpia y la agrega al final.
document.getElementById('agregar').addEventListener('click', function () {
    var copia = document.querySelector('#filas tr').cloneNode(true);
    copia.querySelector('.grav').value = '';
    copia.querySelector('.iva').value = '';
    document.getElementById('filas').appendChild(copia);
    recalcular();
});

// Cualquier cambio dentro de la tabla recalcula (delegación de eventos: un solo listener
// sirve para todas las filas, incluso las que se agregan después).
document.getElementById('detalle').addEventListener('input', recalcular);

// Cambios en los otros importes también recalculan.
['nograv', 'exento', 'otros'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', recalcular);
});

// Botón "x" de cada fila: la elimina, pero nunca deja menos de una fila.
document.getElementById('detalle').addEventListener('click', function (ev) {
    if (ev.target.classList.contains('quitar')) {
        var filas = document.querySelectorAll('#filas tr');
        if (filas.length > 1) {
            ev.target.closest('tr').remove();
            recalcular();
        }
    }
});

// Cálculo inicial al cargar la página (importante en edición, que ya trae datos).
recalcular();