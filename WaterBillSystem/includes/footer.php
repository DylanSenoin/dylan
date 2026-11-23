  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(url('/assets/js/app.js')) ?>"></script>
<script>
(function () {
  const toggle   = document.getElementById('sidebarToggle');
  const sidebar  = document.getElementById('wbSidebar');
  const backdrop = document.getElementById('sidebarBackdrop');

  function closeSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('show');
    backdrop?.classList.remove('show');
  }

  toggle?.addEventListener('click', () => {
    sidebar.classList.toggle('show');
    backdrop.classList.toggle('show');
  });
  backdrop?.addEventListener('click', closeSidebar);

  document.querySelectorAll('.alert-dismissible').forEach((el, i) => {
    setTimeout(() => bootstrap.Alert.getOrCreateInstance(el).close(), 5000 + i * 200);
  });
})();
</script>
</body>
</html>