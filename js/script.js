const menuBtn = document.querySelector(".menu-btn .menu-icon i"),
      navMenu = document.querySelector("header nav ul"),
      darkModeBtn = document.querySelector(".menu-btn .mode-toggle i"),
      body = document.body;

// ----------------- Menu Toggle -----------------
if(menuBtn && navMenu) {
    menuBtn.addEventListener("click", () => {
        menuBtn.classList.toggle("bi-x");
        navMenu.classList.toggle("active");
    });
}

// ----------------- Dark Mode -----------------
if (darkModeBtn) {

    // Function to enable dark mode
    function enableDarkMode(save = true) {
        body.classList.add("dark-mode");
        darkModeBtn.classList.remove("bi-moon");
        darkModeBtn.classList.add("bi-sun");

        if (save) localStorage.setItem("theme", "dark");
    }

    // Function to enable light mode
    function enableLightMode(save = true) {
        body.classList.remove("dark-mode");
        darkModeBtn.classList.remove("bi-sun");
        darkModeBtn.classList.add("bi-moon");

        if (save) localStorage.setItem("theme", "light");
    }

    // ----------------- Detect Theme -----------------
    const savedTheme = localStorage.getItem("theme");

    if (savedTheme === "dark") {
        enableDarkMode();
    } else if (savedTheme === "light") {
        enableLightMode();
    } else {
        const systemDark = window.matchMedia("(prefers-color-scheme: dark)").matches;

        if (systemDark) {
            enableDarkMode(false);
        } else {
            enableLightMode(false);
        }
    }

    // ----------------- Listen for Device Theme Change -----------------
    const mediaQuery = window.matchMedia("(prefers-color-scheme: dark)");

    mediaQuery.addEventListener("change", (e) => {
        if (!localStorage.getItem("theme")) {
            if (e.matches) {
                enableDarkMode(false);
            } else {
                enableLightMode(false);
            }
        }
    });

    // ----------------- Toggle on Button Click -----------------
    darkModeBtn.addEventListener("click", () => {
        if (body.classList.contains("dark-mode")) {
            enableLightMode();
        } else {
            enableDarkMode();
        }
    });
}

// Hero Page Slider 

const slides = document.querySelectorAll(".bg");
let index = 0;

setInterval(() => {
    slides[index].classList.remove("active");
    index = (index + 1) % slides.length;
    slides[index].classList.add("active");
}, 6000);


// Counter stuff in Stats 

const counters = document.querySelectorAll(".counter");

const startCounting = (counter) => {
    const target = +counter.getAttribute("data-target");
    let count = 0;

    const speed = target / 100;

    const update = () => {
        count += speed;

        if (count < target) {
            counter.innerText = Math.floor(count).toLocaleString();
            requestAnimationFrame(update);
        } else {
            counter.innerText = target.toLocaleString();
        }
    };

    update();
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            startCounting(entry.target);
            observer.unobserve(entry.target);
        }
    });
}, { threshold: 0.5 });

counters.forEach(counter => {
    observer.observe(counter);
});

// Testimonial

const swiper = new Swiper(".testimonialSwiper", {
    loop: true,
    spaceBetween: 25,
    autoplay: {
        delay: 3000,
        disableOnInteraction: false,
    },

    pagination: {
        el: ".swiper-pagination",
        clickable: true,
    },

    breakpoints: {
        0: {
            slidesPerView: 1,
            centeredSlides: true,
        },
        768: {
            slidesPerView: 2,
            centeredSlides: false,
        },
        992: {
            slidesPerView: 3,
            centeredSlides: false,
        }
    }
});
// Footer Year
const year = document.querySelector('.year');
if(year) {
    const currentYear = new Date().getFullYear()
    year.textContent = currentYear;
}