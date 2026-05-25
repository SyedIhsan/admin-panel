<?php
declare(strict_types=1);
?>

    </main>

    <footer class="px-4 sm:px-6 lg:px-8 py-10 text-center text-xs text-slate-400">
      © <?= date("Y") ?> Demo Admin.
    </footer>
  </div>
</div>

<script>
  (function () {
    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') window.__sdcCloseAdminDrawer?.();
    });
  })();
</script>

</body>
</html>