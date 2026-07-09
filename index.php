<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Siguraduhing naka-off ang kiosk mode kapag galing sa regular na website
unset($_SESSION['kiosk_mode']);

include_once 'header.php'; 
?>

    <main>
        <section class="hero">
            
            <div class="hero-slider">
                <div class="hero-slide" style="background-image: linear-gradient(rgba(0,0,0,0.3), rgba(0,0,0,0.6)), url('Images/hero/slide-1.webp');">
                </div>
                <div class="hero-slide" style="background-image: linear-gradient(rgba(0,0,0,0.3), rgba(0,0,0,0.6)), url('Images/hero/slide-2.webp');">
                </div>
                <div class="hero-slide" style="background-image: linear-gradient(rgba(0,0,0,0.3), rgba(0,0,0,0.6)), url('Images/hero/slide-3.webp');">
                </div>
            </div>

            <img src="Images/sam.gif" alt="Ajman Water Park Mascot Sam" class="hero-gif gif-sam">
            <img src="Images/nana.gif" alt="Ajman Water Park Mascot Nana" class="hero-gif gif-nana">

            <div class="hero-content">
            <h1>
                The Biggest<br>
                Water Park in Ajman<br>
                <span class="coming-soon-text">The Wait is Finally Over... See You!</span>
            </h1>
            <p>UAE’s Newest, Coolest, and most Thrilling Waterpark!</p>
            </div>
            
            <div class="tabs-wrapper">
                <div class="tabs-container">
                    <div class="tab-buttons">
                        <button class="tab-btn active" data-tab="1" data-icon="fa-ticket-alt">
                            <i class="fas fa-ticket-alt"></i> <span>Tickets</span> <i class="fas fa-chevron-down mobile-arrow"></i>
                        </button>
                        <button class="tab-btn" data-tab="2" data-icon="fa-tags">
                            <i class="fas fa-tags"></i> <span>Offers</span> <i class="fas fa-chevron-down mobile-arrow"></i>
                        </button>
                        <button class="tab-btn" data-tab="3" data-icon="fa-id-card">
                            <i class="fas fa-id-card"></i> <span>Card Passes</span> <i class="fas fa-chevron-down mobile-arrow"></i>
                        </button>
                        <button class="tab-btn" data-tab="4" data-icon="fa-info-circle">
                            <i class="fas fa-info-circle"></i> <span>@Ajmanwaterpark</span> <i class="fas fa-chevron-down mobile-arrow"></i>
                        </button>
                        <button class="tab-btn" data-tab="5" data-icon="fa-id-card">
                            <i class="fas fa-id-card"></i> <span>Birthdays</span>
                        </button>
                    </div>
                    <div class="tab-content" id="tabs-content">
                        
                        <div class="tab-panel active" data-tab="1">
                            <div class="ticket-card">
                                <img src="Images/packages/PACKAGE-2.webp" alt="Day Access For UAE Residents">
                                <div class="ticket-info">
                                    <h3>3 Hours Access Pass</h3>
                                    <p>Enjoy 3 Hours Access for Tourists and Visitors.</p>
                                </div>
                                <div class="ticket-price">
                                    <span>from</span>
                                    <strong>AED 50</strong>
                                    <a href="book.php?preselect=DP-RES" class="btn-book-ticket">Book</a>
                                </div>
                            </div>
                            <div class="ticket-card">
                                <img src="Images/packages/PACKAGE-1.webp" alt="Day Access For Non-Residents">
                                <div class="ticket-info">
                                    <h3>Full Day Access Pass</h3>
                                    <p>Enjoy Full day access for Tourists and Visitors.</p>
                                </div>
                                <div class="ticket-price">
                                    <span>from</span>
                                    <strong>AED 89</strong>
                                    <a href="book.php?preselect=DP-RES" class="btn-book-ticket">Book</a>
                                </div>
                            </div>
                            
                            <!--ORIGINAL NON-UAE RESIDENTS-->    
                            <!--<div class="ticket-card">
                                <img src="Images/packages/PACKAGE-1.webp" alt="Day Access For Non-Residents">
                                <div class="ticket-info">
                                    <h3>Day Access Pass For Non-UAE Residents</h3>
                                    <p>Full day access for tourists and visitors.</p>
                                </div>
                                <div class="ticket-price">
                                    <span>from</span>
                                    <strong>AED 59</strong>
                                    <a href="book.php?preselect=DP-NON" class="btn-book-ticket">Book</a>
                                </div>
                            </div>-->
                            
                        </div>
                        <!--
                        <div class="tab-panel" data-tab="2">
                            <div class="ticket-card">
                                <img src="Images/packages/PACKAGE-9.webp" alt="Winter Camp All Day Splash">
                                <div class="ticket-info">
                                    <h3>Winter Camp All Day Splash</h3>
                                    <p>Full Day Access. Offer valid until 31st of December.</p>
                                </div>
                                <div class="ticket-price">
                                    <span>from</span>
                                    <strong>AED 52.50</strong>
                                    <a href="book.php?preselect=DP-RES" class="btn-book-ticket">Book</a>
                                </div>
                            </div>
                            <div class="ticket-card">
                                <img src="Images/packages/PACKAGE-10.webp" alt="Winter Camp All Day - No Swim">
                                <div class="ticket-info">
                                    <h3>Winter Camp All Day (No Swim)</h3>
                                    <p>Full Day Access without swim. Offer valid until 31st of December.</p>
                                </div>
                                <div class="ticket-price">
                                    <span>from</span>
                                    <strong>AED 31.50</strong>
                                    <a href="book.php?preselect=DP-RES" class="btn-book-ticket">Book</a>
                                </div>
                            </div>
                        </div>

                        <div class="tab-panel" data-tab="3">
                           <div class="pass-grid">
                                <div class="pass-card">
                                    <img src="Images/annual_passes/silver-AP.png" alt="Silver Annual Pass">
                                    <h3>Silver Annual Pass</h3>
                                    <p>Perfect for waterpark lovers who want unlimited fun</p>
                                    <ul>
                                        <li><i class="fas fa-ticket-alt"></i> Unlimited visits</li>
                                    </ul>
                                    <div class="price-footer">
                                        <strong>AED 495</strong>
                                        <a href="details.php?action=buy_pass&id=AP1" class="btn-book-ticket">Book</a>
                                    </div>
                                </div>
                                <div class="pass-card">
                                    <img src="Images/annual_passes/gold-AP.png" alt="Gold Annual Pass">
                                    <h3>Gold Annual Pass</h3>
                                    <p>Ideal for families who visit often and enjoy special perks</p>
                                    <ul>
                                        <li><i class="fas fa-ticket-alt"></i> Unlimited visits & 2 guest passes</li>
                                    </ul>
                                    <div class="price-footer">
                                        <strong>AED 595</strong>
                                        <a href="details.php?action=buy_pass&id=AP2" class="btn-book-ticket">Book</a>
                                    </div>
                                </div>
                                <div class="pass-card">
                                    <img src="Images/annual_passes/platinum-AP.png" alt="Platinum Annual Pass">
                                    <h3>Platinum Annual Pass</h3>
                                    <p>The ultimate VIP experience for frequent visitors...</p>
                                    <ul>
                                        <li><i class="fas fa-ticket-alt"></i> Unlimited visits & 4 guest passes</li>
                                    </ul>
                                    <div class="price-footer">
                                        <strong>AED 795</strong>
                                        <a href="details.php?action=buy_pass&id=AP3" class="btn-book-ticket">Book</a>
                                    </div>-->
                                </div>
                            </div>
                        </div>

                        <div class="tab-panel" data-tab="4">
                            <h3>Park Info</h3>
                            <p><strong>Location:</strong> Ajman Clock Tower, Al Rashidiya 1, Ajman</p>
                            <p><strong>Hours:</strong> Mon-Fri: 11AM-4PM | Sat-Sun: 9AM-5PM</p>
                        </div>
                    </div>
                </div>
            </div>
            
        </section> 
        
        <section class="why-wadi">
            <h2>Dive Into a World of Endless Adventure</h2>
            <p>Discover why we thrill both families and adventurers!</p>
            <div class="why-wadi-slider">
                <div class="slide-card">
                    <img src="Images/why go ajman/thrilling slides.jpg" alt="Waterslides">
                    <div class="slide-content">
                        <h3>Thrilling Slides</h3>
                        <p>Experience heartpounding slides for all ages</p>
                    </div>
                </div>
                <div class="slide-card">
                    <img src="Images/why go ajman/bridge.jpg" alt="Wavepools">
                    <div class="slide-content">
                        <h3>Hanging Bridge</h3>
                        <p>A great fit for multi-step outdoor crossing</p>
                    </div>
                </div>
                <div class="slide-card">
                    <img src="Images/why go ajman/zipline.jpg" alt="Original Waterpark">
                    <div class="slide-content">
                        <h3>Zipline</h3>
                        <p>Extreme Outdoor Adventure</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="exciting-events">
            <div class="container-narrow">
                <h2>Something Exciting Always Awaits You</h2>
                <p>From exclusive ladies nights to family packages, there's always a new way to enjoy the splash!</p>
                <div class="events-grid">
                    <div class="event-card">
                        <img src="Images/packages/PACKAGE-5.webp" alt="Kids Splash Full Day">
                        <div class="event-content">
                            <span>Full Day Access</span>
                            <h3>Kids Splash</h3>
                            <p>Let your kids enjoy a full day of fun and excitement in our dedicated splash zones.</p>
                            <div class="event-footer">
                                <span class="price">From AED 78.75</span>
                                <a href="book.php?preselect=DP-RES" class="btn-book-dark">Book Tickets</a>
                            </div>
                        </div>
                    </div>
                    <div class="event-card">
                        <img src="Images/packages/PACKAGE-8.webp" alt="Family Package No Swim">
                        <div class="event-content">
                            <span>3 Hours</span>
                            <h3>Family Package (No Swim)</h3>
                            <p>2 Adults (No Swim) + 2 Kids + 2 Combo Meals. A perfect 3-hour family treat!</p>
                            <div class="event-footer">
                                <span class="price">From AED 262.50</span>
                                <a href="book.php?preselect=DP-RES" class="btn-book-dark">Book Tickets</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="adventure-features">
            <div class="container-narrow">
                <h2>Why go for Ajman Water Park?</h2>
                <p>UAE’s newest, coolest, and most thrilling waterpark! For all ages, it's the perfect family getaway!</p>
                <div class="features-grid">
                    <div class="feature-item">
                        <i class="fas fa-life-ring"></i>
                        <h3>Safety & Rules</h3>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-utensils"></i>
                        <h3>Delicious Food</h3>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-lock"></i>
                        <h3>Safety Lockers</h3>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-water"></i>
                        <h3>Splash Pads</h3>
                    </div>
                </div>
            </div>
        </section>
        
        
        <!--<section class="events-celebrations" id="packages">
            <h2>Featured Packages</h2>
            <p>We offer a range of water park packages tailored to your needs and budget.</p>
            
            <div class="events-slider">
                <div class="event-slide-card">
                    <img src="Images/packages/resident-pass.webp" alt="UAE Resident Pass">
                    <h3>UAE Resident Pass</h3>
                    <p>Starting from AED 95. Full day access for residents.</p>
                    <a href="book.php?preselect=DP-RES" class="btn-discover">Book Now</a>
                </div>
                <div class="event-slide-card">
                    <img src="Images/packages/non-resident-pass.webp" alt="Non-Resident Pass">
                    <h3>Non-Resident Pass</h3>
                    <p>Starting from AED 125. Full day access for tourists.</p>
                    <a href="book.php?preselect=DP-NON" class="btn-discover">Book Now</a>
                </div>
                <div class="event-slide-card">
                    <img src="Images/packages/featured packages silver.webp" alt="Silver Annual Pass">
                    <h3>Silver Annual Pass</h3>
                    <p>AED 495. Unlimited visits all year round.</p>
                    <a href="details.php?action=buy_pass&id=AP1" class="btn-discover">Book Now</a>
                </div>
                <div class="event-slide-card">
                    <img src="Images/packages/featured packages gold.webp" alt="Gold Annual Pass">
                    <h3>Gold Annual Pass</h3>
                    <p>AED 595. Unlimited visits + 2 Guest Passes.</p>
                    <a href="details.php?action=buy_pass&id=AP2" class="btn-discover">Book Now</a>
                </div>
                <div class="event-slide-card">
                    <img src="Images/packages/featured packages platinum.webp" alt="Platinum Annual Pass">
                    <h3>Platinum Annual Pass</h3>
                    <p>AED 795. Unlimited visits + 4 Guest Passes.</p>
                    <a href="details.php?action=buy_pass&id=AP3" class="btn-discover">Book Now</a>
                </div>
            </div>
        </section>-->

        <section class="visitors-speak" id="Gallery">
            <h2>Visitors Speak</h2>
            <p>Our visitors are our true voice. Their stories and smiles say it all. Here is what they have to say about us.</p>
            <div class="review-platforms">
                <div class="platform-card">
                    <img src="https://i.imgur.com/N71yEaA.png" alt="Google Reviews">
                    <div><span>Google Reviews</span><strong>4.5 ★★★★★</strong></div>
                    <a href="#">See all our reviews</a>
                </div>
                <div class="platform-card">
                    <img src="https://i.imgur.com/i4j1S2o.png" alt="Tripadvisor">
                    <div><span>Tripadvisor</span><strong>4.3 ★★★★☆</strong></div>
                    <a href="#">See all our reviews</a>
                </div>
            </div>
            <div class="reviews-slider">
                <div class="review-card">
                    <div class="review-header"><strong>Fatima A.</strong><span><i class="fab fa-google"></i> 1 week ago</span></div>
                    <div class="review-stars">★★★★★</div>
                    <p>"Amazing fun for the whole family! My kids loved the slides and the splash pads. We will be back soon!"</p>
                </div>
                <div class="review-card">
                    <div class="review-header"><strong>John D.</strong><span><i class="fab fa-google"></i> 2 weeks ago</span></div>
                    <div class="review-stars">★★★★☆</div>
                    <p>"Great place to cool down. The food was good and the staff were very friendly. The lines were a bit long, but it was worth it."</p>
                </div>
                <div class="review-card">
                    <div class="review-header"><strong>Ahmed M.</strong><span><i class="fab fa-google"></i> 1 month ago</span></div>
                    <div class="review-stars">★★★★★</div>
                    <p>"We celebrated my son's birthday here. The team was fantastic and very accommodating. Highly recommended!"</p>
                </div>
                <div class="review-card">
                    <div class="review-header"><strong>Sarah K.</strong><span><i class="fab fa-google"></i> 1 month ago</span></div>
                    <div class="review-stars">★★★★★</div>
                    <p>"Ladies night was perfect! Felt safe and had so much fun with my friends. Clean facilities and great music."</p>
                </div>
            </div>
        </section>

        <section class="ai-planner" id="planner">
            <div class="container-narrow">
                <h2>Plan Your Perfect Day</h2>
                <p>Tell us who's coming and our splash assistant will create a personalized itinerary just for you!</p>
                <div class="planner-form">
                    <div class="planner-inputs">
                        <label for="adults-input">Adults:</label>
                        <input type="number" id="adults-input" min="0" value="0">
                        <label for="children-input">Children:</label>
                        <input type="number" id="children-input" min="0" value="0">
                    </div>
                    <textarea id="planner-input" rows="3" placeholder="Add extra details (e.g., 'we love thrilling rides' or 'we prefer relaxing')..."></textarea>
                    <button id="planner-button" class="btn-book-dark">Generate My Itinerary</button>
                </div>
                <div id="planner-output">
                </div>
            </div>
        </section>

        <!--
        <section id="team" class="my-20 text-center team-section">
            <h2 class="Section-Title text-3xl md:text-5xl font-black text-blue-900 mb-4">Meet Our Team</h2>
            <p class="text-lg text-gray-600 mb-12 max-w-2xl mx-auto px-4">
                All our lifeguards are professionally trained with over 10 years of experience, ensuring your children are safe at all times.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 px-7 md:px-12 lg:px-24 mx-auto max-w-7xl">
                
                <div class="team-card p-4 rounded-2xl card-shadow mx-auto w-full max-w-sm">
                    <div class="team-image-container">
                        <img src="Images/Life Guard/LG2.webp" alt="Hamdy Yehia" class="team-image">
                    </div>
                    <div class="team-info">
                        <h6 class="font-bold text-2xl text-blue-800 team-name">Hamdy Yehia</h6>
                        <p class="text-gray-500 font-semibold uppercase tracking-wide text-sm">Swimming Instructor</p>
                    </div>
                </div>

                <div class="team-card p-4 rounded-2xl card-shadow mx-auto w-full max-w-sm">
                    <div class="team-image-container">
                        <img src="Images/Life Guard/LG1.webp" alt="Ahmed Magdy" class="team-image">
                    </div>
                    <div class="team-info">
                        <h6 class="font-bold text-2xl text-blue-800 team-name">Ahmed Magdy</h6>
                        <p class="text-gray-500 font-semibold uppercase tracking-wide text-sm">Swimming Instructor</p>
                    </div>
                </div>

                <div class="team-card p-4 rounded-2xl card-shadow mx-auto w-full max-w-sm">
                    <div class="team-image-container">
                        <img src="Images/Life Guard/LG3.webp" alt="Mohamed Ferhad" class="team-image">
                    </div>
                    <div class="team-info">
                        <h6 class="font-bold text-2xl text-blue-800 team-name">Mohamed Ferhad</h6>
                        <p class="text-gray-500 font-semibold uppercase tracking-wide text-sm">Life Guard</p>
                    </div>
                </div>
            </div>

            <div id="skills" class="mt-20 max-w-4xl mx-auto px-6 text-left">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-10">
                    
                    <div class="skill">
                        <div class="flex justify-between mb-2">
                            <span class="font-semibold text-blue-900">Communication</span>
                            <span class="font-bold text-blue-900">92%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar green" style="width: 92%"></div>
                        </div>
                    </div>

                    <div class="skill">
                        <div class="flex justify-between mb-2">
                            <span class="font-semibold text-blue-900">Customer Service</span>
                            <span class="font-bold text-blue-900">86%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar blue" style="width: 86%"></div>
                        </div>
                    </div>

                    <div class="skill">
                        <div class="flex justify-between mb-2">
                            <span class="font-semibold text-blue-900">Experience</span>
                            <span class="font-bold text-blue-900">88%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar green" style="width: 88%"></div>
                        </div>
                    </div>

                    <div class="skill">
                        <div class="flex justify-between mb-2">
                            <span class="font-semibold text-blue-900">Certified Lifeguard</span>
                            <span class="font-bold text-blue-900">100%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar blue" style="width: 100%"></div>
                        </div>
                    </div>

                </div>
            </div>
        </section>
-->

        <!--MAP SECTION UMPISA-->
                <section id="map" style="margin-top: -2rem; margin-bottom: 4rem; padding: 0 1rem;">
            <div style="max-width: 1200px; margin: 0 auto;">
                
                <!-- Heading -->
                <div style="text-align: center; margin-bottom: 1.25rem;">
                    <h2 class="Section-Title" style="font-size: clamp(2rem, 4vw, 2.8rem); font-weight: 900; margin: 0; color: rgb(30 58 138 / var(--tw-text-opacity, 1));">
                        Find us
                    </h2>
                    <p style="margin: 0.6rem 0 0; font-size: 1rem; color: #475569; font-weight: 500;">
                        Visit us at our highlighted default location in Ajman
                    </p>
                </div>

                <!-- Map Card -->
                <div style="
                    background: #ffffff;
                    border-radius: 1rem;
                    overflow: hidden;
                    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.10);
                    border: 1px solid rgba(0,0,0,0.06);
                ">
                    
                    <!-- Top Info Bar -->
                    <div style="
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        gap: 1rem;
                        flex-wrap: wrap;
                        padding: 1rem 1.25rem;
                        background: linear-gradient(135deg, #17e8c9, #07d7fc);
                        color: #505050;
                    ">
                        <div>
                            <div style="font-size: 1.05rem; font-weight: 800; line-height: 1.2;">
                                Ajman Clock Tower
                            </div>
                            <div style="font-size: 0.92rem; opacity: 0.88; margin-top: 0.25rem;">
                                Default highlighted location
                            </div>
                        </div>

                        <a href="https://maps.app.goo.gl/yZy5cjAfzjAHhATj7"
                        target="_blank"
                        rel="noopener noreferrer"
                        style="
                                display: inline-flex;
                                align-items: center;
                                justify-content: center;
                                padding: 0.75rem 1.1rem;
                                background: #ffffff;
                                color: #0f172a;
                                text-decoration: none;
                                font-size: 0.92rem;
                                font-weight: 800;
                                border-radius: 999px;
                                box-shadow: 0 6px 16px rgba(0,0,0,0.15);
                                transition: all 0.25s ease;
                        "
                        onmouseover="this.style.transform='translateY(-2px)'"
                        onmouseout="this.style.transform='translateY(0)'">
                            Get Directions
                        </a>
                    </div>

                    <!-- Map -->
                    <div style="position: relative; width: 100%; height: 430px;">
                        <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3604.3674486384502!2d55.45394367599413!3d25.392508023760975!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3e5f5929e209cf93%3A0xf1c010358ba72998!2sAjman%20Clock%20Towers!5e0!3m2!1sen!2sae!4v1773681050060!5m2!1sen!2sae" width="100%" height="430" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>

                        <!-- Floating Badge -->
                        <div style="
                            position: absolute;
                            top: 1rem;
                            left: 1rem;
                            background: rgba(255,255,255,0.95);
                            color: #0f172a;
                            padding: 0.65rem 0.95rem;
                            border-radius: 999px;
                            font-size: 0.88rem;
                            font-weight: 800;
                            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
                            backdrop-filter: blur(6px);
                        ">
                            📍 Highlighted: Ajman Clock Tower
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!--MAP SECTION DULO-->

        <section class="newsletter">
            <div class="newsletter-content">
                <h2>Sign up for our Newsletter</h2>
                <p>Be the first to know about our exclusive events, waterpark updates and special deals.</p>
                <form class="newsletter-form">
                    <input type="text" placeholder="First Name">
                    <input type="text" placeholder="Last Name">
                    <input type="email" placeholder="Your Email">
                    <button type="submit" class="btn-book-dark">Subscribe</button>
                </form>
                <p class="terms">I consent to receive exclusive marketing communications...</p>
            </div>
            <div class="wave-footer"></div>
        </section>

    </main>

<?php 
include_once 'footer.php'; 
?>