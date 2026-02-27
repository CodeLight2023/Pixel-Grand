const menuBtn = document.querySelector(".menu-btn .menu-icon i"),
      navMenu = document.querySelector("header nav ul"),
      darkModeBtn = document.querySelector(".menu-btn .mode-toggle i"),
      body = document.body;

// ----------------- Menu Toggle -----------------
menuBtn.addEventListener("click", () => {
    menuBtn.classList.toggle("bi-x");
    navMenu.classList.toggle("active");
});

// ----------------- Dark Mode -----------------

// Function to enable dark mode
function enableDarkMode() {
    body.classList.add("dark-mode");
    darkModeBtn.classList.remove("bi-moon");
    darkModeBtn.classList.add("bi-sun");
    localStorage.setItem("theme", "dark"); // save preference
}

// Function to enable light mode
function enableLightMode() {
    body.classList.remove("dark-mode");
    darkModeBtn.classList.remove("bi-sun");
    darkModeBtn.classList.add("bi-moon");
    localStorage.setItem("theme", "light"); // save preference
}

// ----------------- Detect previous theme -----------------
const savedTheme = localStorage.getItem("theme");

// Apply saved theme if available, else system preference
if (savedTheme === "dark") {
    enableDarkMode();
} else if (savedTheme === "light") {
    enableLightMode();
} else {
    // No saved preference, check system
    if (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) {
        enableDarkMode();
    } else {
        enableLightMode();
    }
}

// ----------------- Toggle on button click -----------------
darkModeBtn.addEventListener("click", () => {
    if (body.classList.contains("dark-mode")) {
        enableLightMode();
    } else {
        enableDarkMode();
    }
});