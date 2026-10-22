</main>
<footer class="bottombar">
    <small>Powered by a tiny PHP image host.</small>
</footer>
<script>
(function () {
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var cur = document.documentElement.getAttribute('data-theme') || 'light';
        var next = cur === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
    });
})();
</script>
</body>
</html>
