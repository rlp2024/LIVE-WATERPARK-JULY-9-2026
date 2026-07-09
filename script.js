// Hintayin muna mag-load ang buong page bago paganahin ang JS
document.addEventListener('DOMContentLoaded', function() {

    // ===== LOGIC PARA SA MOBILE MENU (Hamburger) =====
    const menuToggle = document.querySelector('.mobile-menu-toggle');
    const navLinks = document.querySelector('.nav-links');

    if (menuToggle && navLinks) {
        menuToggle.addEventListener('click', () => {
            navLinks.classList.toggle('active');
        });
    }

    // ===== LOGIC PARA SA MOBILE DROPDOWNS =====
    const dropdownLinks = document.querySelectorAll('.nav-links .nav-link-dropdown');

    dropdownLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            if (window.innerWidth <= 900) { // Gumagana lang sa mobile
                e.preventDefault(); 
                const parentItem = link.parentElement;
                parentItem.classList.toggle('mobile-active'); 
            }
        });
    });

    // ===== LOGIC PARA ISARA ANG MOBILE MENU PAGKA-CLICK =====
    const allMenuLinks = document.querySelectorAll('.nav-links a');

    allMenuLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            if (window.innerWidth <= 900) {
                if (!link.classList.contains('nav-link-dropdown')) {
                    if (navLinks) {
                        navLinks.classList.remove('active');
                    }
                    document.querySelectorAll('.nav-item-dropdown.mobile-active').forEach(item => {
                        item.classList.remove('mobile-active');
                    });
                }
            }
        });
    });


    // ===== BAGONG "WILD WADI" TAB LOGIC (DESKTOP & MOBILE) =====
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabPanels = document.querySelectorAll('.tab-panel');
    const tabContent = document.getElementById('tabs-content'); // Ang container ng lahat ng panels

    // (INAYOS) Binalot sa 'if (tabContent)' para sa index.php lang tumakbo
    if (tabContent) {
        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const tabNumber = button.getAttribute('data-tab');
                const isMobile = window.innerWidth <= 900;
                const activePanel = document.querySelector(`.tab-panel[data-tab="${tabNumber}"]`);

                if (isMobile) {
                    // --- Mobile "Wild Wadi" Logic ---
                    if (button.classList.contains('active')) {
                        // 1. Kung pinindot ang active tab, i-toggle ang content
                        tabContent.classList.toggle('mobile-visible');
                        // Ayusin ang arrow
                        if (button.querySelector('.mobile-arrow')) { // Check kung may arrow
                            button.querySelector('.mobile-arrow').style.transform = 
                                tabContent.classList.contains('mobile-visible') ? 'rotate(180deg)' : 'rotate(0deg)';
                        }
                    } else {
                        // 2. Kung pinindot ang inactive tab
                        tabButtons.forEach(btn => btn.classList.remove('active'));
                        button.classList.add('active');
                        
                        document.querySelectorAll('.mobile-arrow').forEach(arrow => {
                            if (arrow) arrow.style.transform = 'rotate(0deg)';
                        });
                        
                        tabContent.classList.add('mobile-visible');
                        if (button.querySelector('.mobile-arrow')) { // Check kung may arrow
                            button.querySelector('.mobile-arrow').style.transform = 'rotate(180deg)';
                        }
                        
                        tabPanels.forEach(panel => panel.classList.remove('active'));
                        if (activePanel) {
                            activePanel.classList.add('active');
                        }
                    }
                } else {
                    // --- Desktop Logic (Original) ---
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    tabPanels.forEach(panel => panel.classList.remove('active'));
                    
                    button.classList.add('active');
                    if (activePanel) {
                        activePanel.classList.add('active');
                    }
                }
            });
        });

        // Initial check pagka-load ng page
        function initializeTabs() {
            if (window.innerWidth <= 900) {
                tabContent.classList.add('mobile-visible');
                const activeArrow = document.querySelector('.tab-btn.active .mobile-arrow');
                if (activeArrow) {
                    activeArrow.style.transform = 'rotate(180deg)'; // Naka-DOWN
                }
            } else {
                 tabContent.classList.remove('mobile-visible');
            }
        }
        initializeTabs(); // Patakbuhin pagka-load
        
        // Siguraduhin na mag-re-check kung mag-resize
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(initializeTabs, 250);
        });
    } // <-- (INAYOS) Dito nagsasara ang 'if (tabContent)'


    // ===== LOGIC PARA SA AI PLANNER =====
    const plannerButton = document.getElementById('planner-button');
    
    // (INAYOS) Binalot sa 'if (plannerButton)' para sa index.php lang tumakbo
    if (plannerButton) {
        // (INAYOS) Inilipat ang mga ito sa loob ng 'if'
        const plannerOutput = document.getElementById('planner-output');
        const adultsInput = document.getElementById('adults-input');
        const childrenInput = document.getElementById('children-input');
        const plannerInput = document.getElementById('planner-input');

        plannerButton.addEventListener('click', () => {
            // Check kung may laman bago gamitin
            if (!plannerOutput || !adultsInput || !childrenInput || !plannerInput) return;

            const adults = parseInt(adultsInput.value, 10) || 0;
            const children = parseInt(childrenInput.value, 10) || 0;
            const description = plannerInput.value;

            if (adults === 0 && children === 0) {
                plannerOutput.innerHTML = "<h3 style='color: red;'>Error!</h3><p>Please enter the number of adults or children for your group!</p>";
                plannerOutput.style.display = 'block';
                return;
            }
            
            plannerOutput.style.display = 'block';
            plannerOutput.innerHTML = '<div class="flex justify-center items-center p-4"><div class="loader"></div><p style="margin-left: 10px; display: inline-block;">Generating a splash-tastic plan...</p></div>';

            setTimeout(() => {
                const plan = generateLocalPlan(adults, children, description);
                plannerOutput.innerHTML = plan;
            }, 1500);
        });
    } // <-- (INAYOS) Dito nagsasara ang 'if (plannerButton)'
    
    // Ito 'yung "utak" ng AI Planner
    function generateLocalPlan(adults, children, description) {
        const totalPeople = adults + children;
        const lowerCaseDesc = description.toLowerCase();
        
        let planDetails = `
            <h3>✨ Your Personalized Itinerary for ${totalPeople} Guest(s)! ✨</h3>
            <p>Here's a suggested plan for your group of <strong>${adults} adult(s)</strong> and <strong>${children} child(ren)</strong> to maximize your fun at Ajman Water Park:</p>
        `;

        const wantsThrill = lowerCaseDesc.includes('thrill') || lowerCaseDesc.includes('adventure') || lowerCaseDesc.includes('slides');
        
        if (adults > 0 && children > 0) {
            planDetails += `
                <h4 style="font-weight: 700; font-size: 1.1rem; color: #005f9d; margin-top:15px;">Family Fun Plan:</h4>
                <ul>
                    <li><strong>Morning:</strong> Start at the <strong>Splash Pads</strong>! It's perfect for the little ones.</li>
                    ${wantsThrill ? '<li><strong>Late Morning:</strong> For the thrill-seekers, try the <strong>giant water slides</strong>!</li>' : ''}
                    <li><strong>Lunch:</strong> Grab some kid-friendly meals at our food court.</li>
                    <li><strong>Afternoon:</strong> Enjoy a relaxing float together on the lazy river.</li>
                </ul>
            `;
        } else if (adults > 0 && children === 0) {
             planDetails += `
                <h4 style="font-weight: 700; font-size: 1.1rem; color: #005f9d; margin-top:15px;">Adults' Plan:</h4>
                <ul>
                    <li><strong>Morning:</strong> Head straight for the <strong>Thrilling Slides</strong> to get that adrenaline pumping.</li>
                    <li><strong>Lunch:</strong> Refuel with snacks from our kiosks.</li>
                    ${wantsThrill ? '<li><strong>Afternoon:</strong> Challenge your friends on all the competitive slides!</li>' : '<li><strong>Afternoon:</strong> Take a gentle journey down the lazy river.</li>'}
                </ul>
            `;
        } else {
             planDetails += `
                <h4 style="font-weight: 700; font-size: 1.1rem; color: #005f9d; margin-top:15px;">Kids' Splash Plan:</h4>
                <ul>
                    <li><strong>Morning:</strong> The <strong>Splash Pads</strong> are the perfect place to start.</li>
                    <li><strong>Lunch:</strong> Time for a break with some yummy pizza and juice.</li>
                    <li><strong>Afternoon:</strong> Try the smaller slides and explore all the water features.</li>
                </ul>
                <p style="margin-top: 15px; font-size: 0.9rem; color: #d9534f;"><strong>Note:</strong> Please ensure all children are accompanied by a supervising adult.</p>
            `;
        }
        
        planDetails += `<hr style="margin: 15px 0;">`;
        planDetails += `<h4 style="font-weight: 700; font-size: 1.1rem; color: #0a4c8c;">Suggested Ticket Package:</h4>`;

        if (adults >= 2 && children >= 2) {
            planDetails += `<p style="margin-top: 10px;">For your group size, the <strong>Family Package (2 Adults + 2 Kids)</strong> would be the most cost-effective!</p>`;
        } else {
            planDetails += `<p style="margin-top: 10px;">We recommend the <strong>Full Day Access</strong> tickets to make the most of your visit.</p>`;
        }
        
        planDetails += `<p style="margin-top: 20px; font-size: 0.9rem; color: #777;"><em>This is just a suggestion. Enjoy your splash-tastic day!</em></p>`;
        return planDetails;
    }


    // ===== LOGIC PARA SA MGA SLIDERS (gamit ang jQuery) =====
    if (typeof jQuery == 'undefined') {
        console.error("jQuery is not loaded!");
    } else {
        $(document).ready(function(){
            
            // (INAYOS) Nagdagdag ng check kung nage-exist ang slider bago paganahin
            if ($('.hero-slider').length) {
                $('.hero-slider').slick({
                    dots: true,
                    arrows: true, 
                    infinite: true,
                    speed: 1000,
                    fade: true,
                    cssEase: 'linear',
                    autoplay: true,
                    autoplaySpeed: 4000,
                    slidesToShow: 1,
                    slidesToScroll: 1
                });
            }

            if ($('.why-wadi-slider').length) {
                $('.why-wadi-slider').slick({
                    dots: true,
                    infinite: true,
                    speed: 500,
                    slidesToShow: 3,
                    slidesToScroll: 1,
                    autoplay: true,
                    autoplaySpeed: 3000,
                    responsive: [
                        { breakpoint: 1024, settings: { slidesToShow: 2 } },
                        { breakpoint: 600, settings: { slidesToShow: 1 } }
                    ]
                });
            }

            if ($('.events-slider').length) {
                $('.events-slider').slick({
                    dots: true,
                    infinite: true,
                    speed: 500,
                    slidesToShow: 4,
                    slidesToScroll: 1,
                    autoplay: true,
                    autoplaySpeed: 3500,
                    responsive: [
                        { breakpoint: 1024, settings: { slidesToShow: 2 } },
                        { breakpoint: 600, settings: { slidesToShow: 1 } }
                    ]
                });
            }

            if ($('.reviews-slider').length) {
                $('.reviews-slider').slick({
                    dots: true,
                    infinite: true,
                    speed: 500,
                    slidesToShow: 3,
                    slidesToScroll: 1,
                    autoplay: true,
                    autoplaySpeed: 4000,
                    responsive: [
                        { breakpoint: 1024, settings: { slidesToShow: 2 } },
                        { breakpoint: 600, settings: { slidesToShow: 1 } }
                    ]
                });
            }

        });
    }
});
/** 
//weather outside start
<script>
const apiKey = "a6bb2680aaa147281b7b872ebd9e6069"; // OpenWeatherMap API key
const city = "Dubai"; // change to your city
const units = "Celsius"; // Celsius

async function fetchWeather() {
  try {
    const response = await fetch(`https://api.openweathermap.org/data/2.5/weather?q=${city}&units=${units}&appid=${apiKey}`);

    if (!response.ok) {
      throw new Error("Weather data not available");
    }

    const data = await response.json();
    const temp = Math.round(data.main.temp);

    document.getElementById("weather-temp").textContent = `${temp}°C`;
  } catch (error) {
    console.error(error);
    document.getElementById("weather-temp").textContent = "N/A";
  }
}

// Run on page load
fetchWeather();
</script>


//weather outside end 
*/


// =================================================== //
// == Team Section - Skills Progress Bar Animation  == //
// =================================================== //

const skillsSection = document.getElementById('skills');
const progressBars = document.querySelectorAll('.progress-bar');

if (skillsSection && progressBars.length > 0) {
    const skillsObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            // Kapag nakita na ng user ang #skills section sa screen
            if (entry.isIntersecting) {
                progressBars.forEach(bar => {
                    // Kunin ang percentage mula sa 'data-width' attribute sa HTML
                    const targetWidth = bar.dataset.width;
                    // I-apply ang width para mag-animate ang bar
                    bar.style.width = targetWidth;
                });
                // I-stop na ang pagbabantay para hindi na umulit ang animation
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.5 // Magsisimula ang animation pag 50% na ng section ang kita
    });

    skillsObserver.observe(skillsSection);
}