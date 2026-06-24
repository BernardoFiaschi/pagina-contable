</main>
    <script>
    /* Modo claro/oscuro. Inline para no depender de rutas de archivos.
       Guarda la preferencia en localStorage; respeta el tema del sistema si no hay nada guardado. */
    (function () {
        var KEY = 'tema';
        function aplicar(tema) {
            var oscuro = (tema === 'oscuro');
            document.body.classList.toggle('oscuro', oscuro);
            var btn = document.getElementById('toggle-tema');
            if (btn) {
                btn.textContent = oscuro ? '\u2600\uFE0F' : '\uD83C\uDF19';
                btn.title = oscuro ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro';
            }
        }
        var guardado = localStorage.getItem(KEY);
        if (!guardado) { guardado = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'oscuro' : 'claro'; }
        aplicar(guardado);
        document.addEventListener('click', function (ev) {
            if (ev.target && ev.target.id === 'toggle-tema') {
                var nuevo = document.body.classList.contains('oscuro') ? 'claro' : 'oscuro';
                localStorage.setItem(KEY, nuevo);
                aplicar(nuevo);
            }
        });
    })();
    </script>
</body>
</html>