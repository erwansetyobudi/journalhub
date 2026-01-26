(function () {
  const key = "oai_theme";
  const root = document.documentElement;

  function setTheme(t) {
    root.setAttribute("data-bs-theme", t);
    localStorage.setItem(key, t);
    const btn = document.getElementById("themeToggle");
    if (btn) btn.textContent = (t === "dark") ? "Light Mode" : "Dark Mode";
  }

  window.toggleTheme = function () {
    const cur = root.getAttribute("data-bs-theme") || "light";
    setTheme(cur === "dark" ? "light" : "dark");
  };

  const saved = localStorage.getItem(key);
  setTheme(saved || "light");
})();
