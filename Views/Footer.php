            </div> <!-- Cierre content-wrapper -->

            <!-- Footer General -->
            <footer class="app-footer">
                <p>&copy; <?= date('Y') ?> <span class="gold-text">Amazon</span> Market - Todos los derechos reservados.</p>
            </footer>
        </main> <!-- Cierre main-content -->
    </div> <!-- Cierre app-layout -->

    <!-- Script de Alertas Modales -->
    <script src="<?= BASE_URL ?>/Assets/js/modal.js"></script>
    <script src="<?= BASE_URL ?>/Assets/js/modal-form.js"></script>

    <!-- Scripts de módulos API -->
    <script src="<?= BASE_URL ?>/Assets/js/productos.js"></script>
    <script src="<?= BASE_URL ?>/Assets/js/clientes.js"></script>
    <script src="<?= BASE_URL ?>/Assets/js/usuarios.js"></script>
    <script src="<?= BASE_URL ?>/Assets/js/venta.js"></script>
    <script src="<?= BASE_URL ?>/Assets/js/reportes.js"></script>

    <!-- Auto-ocultar alertas flash después de 4 segundos -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var sidebar = document.getElementById('sidebar');
        var toggle = document.getElementById('sidebar_toggle');

        if (sidebar && toggle) {
            if (localStorage.getItem('sidebar_collapsed') === '1') {
                sidebar.classList.add('collapsed');
            }
            toggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
            });
        }

        const flashSuccess = document.getElementById('flash_success');
        const flashError = document.getElementById('flash_error');
        
        if (flashSuccess) {
            setTimeout(() => {
                flashSuccess.style.transition = 'opacity 0.5s, transform 0.5s';
                flashSuccess.style.opacity = '0';
                flashSuccess.style.transform = 'translateY(-10px)';
                setTimeout(() => flashSuccess.remove(), 500);
            }, 4000);
        }
        
        if (flashError) {
            setTimeout(() => {
                flashError.style.transition = 'opacity 0.5s, transform 0.5s';
                flashError.style.opacity = '0';
                flashError.style.transform = 'translateY(-10px)';
                setTimeout(() => flashError.remove(), 500);
            }, 5000);
        }
    });
    </script>
</body>
</html>
