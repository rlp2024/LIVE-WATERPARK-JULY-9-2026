<?php 
// Check muna kung walang session bago mag start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajman Water Park - The Biggest Water Park In Ajman</title>
    
    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
   
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.css"/>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick-theme.css"/>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/vanillajs-datepicker@1.3.4/dist/css/datepicker.min.css">
   
    <link rel="stylesheet" href="style.css">
    <!-- Favicon (recommended: .ico for broad support) -->
    <link rel="shortcut icon" href="/awpfav.png" type="image/x-icon">
    
    <!-- Meta Pixel Code -->
    <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '2363340107511852');
    fbq('track', 'PageView');
    </script>
    <!-- End Meta Pixel Code -->
</head>

<body>
    
    <!-- Meta Pixel Code -->
    <noscript><img height="1" width="1" style="display:none"
    src="https://www.facebook.com/tr?id=2363340107511852&ev=PageView&noscript=1"
    /></noscript>
    <!-- End Meta Pixel Code -->

    <header class="header">
        <div class="header-top">
            <div class="container">
                <span>Opens:  Mon - Sun: 10AM - 9PM</span>
                <span>
                    Call Us: <strong>+971 52 120 7573</strong>
                    
                </span>
            </div>
        </div>
<nav class="main-nav">
            <div class="container" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                
                <a href="index.php" class="logo">
                    <img src="Images/HEADER LOGO.png" alt="Ajman Water Park Logo" style="height: 100px;">
                </a>
               
                <!-- Hamburger Menu for Mobile -->
                <button class="mobile-menu-toggle" style="background: transparent; border: none; font-size: 28px; color: #fff; cursor: pointer; padding-right: 15px;">
                    <i class="fas fa-bars"></i>
                </button>

                <ul class="nav-links" id="navLinksMenu">
                    <li class="nav-item-dropdown">
                        <a href="#" class="nav-link-dropdown">Plan Your Visit <i class="fas fa-chevron-down"></i></a>
                        <div class="dropdown-menu">
                            <a href="index.php#info">Park Info</a>
                            <a href="#">Before You Visit</a>
                            <a href="index.php#info">Contact Us</a>
                        </div>
                    </li>
                     <li class="nav-item-dropdown">
                        <a href="javascript:void(0)" class="nav-link-dropdown">
                            Rules & Regulations <i class="fas fa-chevron-down"></i>
                        </a>
                        <div class="dropdown-menu">
                            <a href="index.php#packages">Events</a>
                            <a href="index.php#packages">School Tours</a>
                            <a href="index.php#packages">Group Tours</a>
                            <a href="index.php#packages">Birthdays</a>
                            <a href="#">Become a Reseller</a>
                            <a href="#">Sponsorships</a>
                        </div>
                    </li>
                     <li><a href="index.php#Gallery">Gallery</a></li>

                     <li>
                        <a href="index.php#team">Our Team</a>
                        <div class="hover-box">Learn more about our team</div>
                     </li>
  
                      <li class="nav-item">
                        <a href="index.php#team">About Us</a>
                        <div class="hover-box">Know more with us</div>
                      </li>                
                    
                    <li class="coh-list-item coh-ce-cpt_site_header_park_timing-437374bc">
                        <i class="fa-solid fa-cloud-sun weather-icon"></i>
                        <div class="coh-block">
                            <div id="block-dpr-dprweatherblock">
                                <a href="#" id="weather-temp">--°C</a>
                            </div>
                        </div>
                    </li>
                    
                    <!-- ✅ INILIPAT SA LOOB NG UL ANG BUTTONS PARA KASAMA SA TEAL BACKGROUND -->
                    <li class="action-buttons-li">
                        <a href="topup.php" class="btn-book-now" style="background-color: #0ea5e9; border: none;">Top-up Wallet</a>
                        <a href="book.php" class="btn-book-now" style="background-color: #ff0000; border: none;">Book Now</a>
                    </li>
                </ul>

            </div>
        </nav>
    </header>
<style>
/* Basic nav styling */
.main-nav { position: relative; } /* Important para dito bumatay ang dropdown */
.nav-item-dropdown { position: relative; list-style: none; }
.nav-link-dropdown { cursor: pointer; text-decoration: none; color: inherit; display: flex; align-items: center; justify-content: center; gap: 6px; }

/* Dropdown Menu (Desktop) */
.dropdown-menu { display: none; position: absolute; top: 100%; left: 0; background: #fff; min-width: 200px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); z-index: 1000; text-align: left; border-radius: 8px;}
.dropdown-menu a { display: block; padding: 10px 15px; text-decoration: none; color: #000; }
.dropdown-menu a:hover { background: #f2f2f2; color: #0ea5e9; }
.nav-item-dropdown.active .dropdown-menu { display: block; }
.nav-item-dropdown.active i { transform: rotate(180deg); transition: 0.3s; }

/* Desktop Flex Layout */
@media (min-width: 992px) {
    .mobile-menu-toggle { display: none !important; }
    .nav-links { display: flex; flex: 1; justify-content: flex-end; align-items: center; gap: 20px; margin: 0; padding: 0; list-style: none;}
    
    /* Push the buttons slightly away from the regular links */
    .action-buttons-li { display: flex; gap: 10px; margin-left: 20px; }
}

/* --- MOBILE RESPONSIVENESS (Android / Apple) --- */
@media (max-width: 991px) {
    .header-top { display: none; } /* Hide text header top for cleaner mobile */
    .mobile-menu-toggle { display: block !important; }
    .main-nav .container { padding: 0 15px; }

    /* The Teal Dropdown Menu */
    .nav-links {
        display: none; 
        flex-direction: column;
        position: absolute;
        top: 100%; 
        left: 0;
        width: 100%; 
        background-color: #11999E; /* Teal color */
        /* ✅ Inayos ang padding para gitna at may allowance sa ilalim */
        padding: 20px 20px 40px 20px; 
        box-shadow: 0 10px 15px rgba(0,0,0,0.2);
        z-index: 9999;
        box-sizing: border-box; 
        margin: 0;
        list-style: none;
        
        /* ✅ PERFECT SCROLLING PARA SA MOBILE */
        max-height: calc(100vh - 80px); /* Computed para hindi lumagpas sa screen */
        overflow-y: auto; 
        -webkit-overflow-scrolling: touch; /* Smooth scrolling sa iPhone/Android */
    }

    .nav-links.active-mobile {
        display: flex;
    }

    .nav-links > li { margin-bottom: 15px; text-align: center; width: 100%; }
    .nav-links > li:last-child { margin-bottom: 0; }
    
    .nav-links a { 
        color: #ffffff !important; 
        font-size: 16px; 
        font-weight: bold; 
        display: block; 
        padding: 10px 0; /* Para mas madaling pindutin sa phone */
    }
    
    .coh-list-item { justify-content: center !important; }

    /* Dropdown inside Mobile */
    .dropdown-menu {
        position: static;
        box-shadow: none;
        background: rgba(255, 255, 255, 0.1); 
        margin-top: 10px;
        text-align: center;
        border-radius: 8px; 
        overflow: hidden;
    }
    .dropdown-menu a {
        color: #e0f2f1 !important; 
        font-size: 14px;
        padding: 12px; 
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .dropdown-menu a:last-child { border-bottom: none; }

    /* Top-up and Book Now Buttons Stacked */
    .action-buttons-li {
        display: flex;
        flex-direction: column;
        gap: 15px;
        margin-top: 15px !important;
        padding-top: 25px;
        border-top: 1px solid rgba(255, 255, 255, 0.2); /* Linya para mahiwalay ang buttons */
        width: 100%;
    }
    
    .action-buttons-li a {
        width: 100%;
        text-align: center;
        padding: 15px !important;
        border-radius: 50px;
        box-sizing: border-box;
        color: #fff !important; 
        display: block;
        font-size: 15px;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
}
</style>
<script>
document.addEventListener("DOMContentLoaded", function () {
    // 1. Dropdown Logic (Na-fix ang pagbalik ng arrow)
    const dropdowns = document.querySelectorAll(".nav-item-dropdown");

    dropdowns.forEach(dropdown => {
        const trigger = dropdown.querySelector(".nav-link-dropdown");
        if (trigger) {
            trigger.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation(); // Pinipigilan mag-double fire ang event
                
                // Kapag pinindot ulit ang parehong dropdown, isasara niya ito
                if (dropdown.classList.contains("active")) {
                    dropdown.classList.remove("active");
                } else {
                    // Isara muna ang ibang dropdowns bago buksan ang bago
                    dropdowns.forEach(d => d.classList.remove("active"));
                    dropdown.classList.add("active");
                }
            });
        }
    });

    // Isara ang dropdown kapag pumindot sa ibang part ng screen
    document.addEventListener("click", function (e) {
        dropdowns.forEach(dropdown => {
            if (!dropdown.contains(e.target)) {
                dropdown.classList.remove("active");
            }
        });
    });

    // 2. Mobile Menu Toggle Logic
    const menuToggle = document.querySelector(".mobile-menu-toggle");
    const navLinks = document.getElementById("navLinksMenu");
    const menuIcon = menuToggle ? menuToggle.querySelector("i") : null;

    // Paggawa ng function para madaling isara ang menu
    function closeMobileMenu() {
        if (navLinks && navLinks.classList.contains("active-mobile")) {
            navLinks.classList.remove("active-mobile");
            if (menuIcon) {
                menuIcon.classList.remove("fa-times");
                menuIcon.classList.add("fa-bars");
            }
            // I-reset din lahat ng dropdowns sa loob pag sara ng menu
            dropdowns.forEach(d => d.classList.remove("active"));
        }
    }

    if (menuToggle && navLinks) {
        menuToggle.addEventListener("click", function (e) {
            e.preventDefault();
            e.stopPropagation();
            
            navLinks.classList.toggle("active-mobile");
            
            if (navLinks.classList.contains("active-mobile")) {
                menuIcon.classList.remove("fa-bars");
                menuIcon.classList.add("fa-times");
            } else {
                menuIcon.classList.remove("fa-times");
                menuIcon.classList.add("fa-bars");
                dropdowns.forEach(d => d.classList.remove("active"));
            }
        });
    }

    // 3. ✅ AUTO-CLOSE MOBILE MENU PAG MAY PININDOT NA LINK (Gaya ng Our Team, Gallery, etc.)
    const allMenuLinks = document.querySelectorAll(".nav-links a:not(.nav-link-dropdown)");
    allMenuLinks.forEach(link => {
        link.addEventListener("click", function () {
            // Kapag may pinindot na destination link, isasara agad ang buong menu
            closeMobileMenu();
        });
    });

    // 4. Weather Fetch
    const apiKey = "a6bb2680aaa147281b7b872ebd9e6069"; 
    const city = "Ajman, UAE"; 
    const units = "metric"; 

    async function fetchWeather() {
        try {
            const response = await fetch(`https://api.openweathermap.org/data/2.5/weather?q=${city}&units=${units}&appid=${apiKey}`);
            if (!response.ok) throw new Error("Weather data not available");

            const data = await response.json();
            const temp = Math.round(data.main.temp);

            const weatherEl = document.getElementById("weather-temp");
            if(weatherEl) weatherEl.textContent = `${temp}°C`;
        } catch (error) {
            console.error(error);
            const weatherEl = document.getElementById("weather-temp");
            if(weatherEl) weatherEl.textContent = "N/A";
        }
    }
    fetchWeather();
});
</script>

<!--    
<style>
/* CSS para sa Snow */
.snowflake {
    position: fixed;
    top: -20px;
    z-index: 99999; /* Tinaasan ko para sure na nasa ibabaw */
    color: #ffffff; /* DARK BLUE muna para makita mo agad (Palitan ng #FFF kung okay na) */
    font-size: 1em;
    user-select: none;
    pointer-events: none; /* Para hindi makasagabal sa clicks */
    animation-name: fall;
    animation-timing-function: linear;
    text-shadow: 0 0 5px rgba(255,255,255,0.8); /* May glow effect */
}

@keyframes fall {
    0% {
        transform: translateY(-20px) translateX(0px) rotate(0deg);
        opacity: 1;
    }
    100% {
        transform: translateY(100vh) translateX(20px) rotate(360deg);
        opacity: 0.3;
    }
}
</style>

<script>
// JS para sa Snow Generator
function createSnowflake() {
    const snowflake = document.createElement('div');
    snowflake.classList.add('snowflake');
    
    // Pwede mong palitan ang icon dito (❄, ❅, ❆, o kahit tuldok •)
    snowflake.innerHTML = '❄'; 
    
    // Random Position
    snowflake.style.left = Math.random() * 100 + 'vw';
    
    // Random Speed (2s to 5s)
    const duration = Math.random() * 5 + 4; 
    snowflake.style.animationDuration = duration + 's';
    
    // Random Size (10px to 25px)
    const size = Math.random() * 20 + 15;
    snowflake.style.fontSize = size + 'px';
    
    document.body.appendChild(snowflake);

    // Burahin pagtapos mahulog para hindi bumigat ang site
    setTimeout(() => {
        snowflake.remove();
    }, duration * 1000); 
}

// Gumawa ng snow kada 200 milliseconds
setInterval(createSnowflake, 200);
</script>-->