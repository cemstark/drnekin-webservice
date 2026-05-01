  </div><!-- /.app-shell -->
  <button class="menu-toggle" id="appMenuBtn" aria-label="Menuyu ac" style="position:fixed;top:14px;left:14px;z-index:60">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <script>
    (function(){
      var btn = document.getElementById('appMenuBtn');
      var sb = document.getElementById('appSidebar');
      if (btn && sb) btn.addEventListener('click', function(){ sb.classList.toggle('is-open'); });
    })();
  </script>
</body>
</html>
