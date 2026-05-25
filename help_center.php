<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help Center - Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: #f9fafb; color: #1f2937; }
        
        /* --- Header Section --- */
        header {
            background-color: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .logo-section img {
            height: 35px;
            border-radius: 4px;
        }
        .logo-section h1 {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
        }
        .back-btn {
            background-color: #0f172a;
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.3s ease;
        }
        .back-btn:hover {
            background-color: #1e293b;
            transform: translateY(-1px);
        }

        /* --- Content Container --- */
        .container {
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .section-title {
            text-align: center;
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 40px;
            color: #111827;
        }

        /* --- FAQ Section --- */
        .faq-section {
            background-color: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .faq-section h2 {
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 20px;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .faq-item {
            border-bottom: 1px solid #f3f4f6;
            padding: 18px 0;
        }
        .faq-item:last-child {
            border-bottom: none;
        }
        .faq-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        .faq-title h3 {
            font-size: 15px;
            font-weight: 600;
            color: #374151;
        }
        .faq-title i {
            color: #9ca3af;
            transition: transform 0.3s ease;
        }
        .faq-content {
            margin-top: 12px;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
            display: none;
        }
        .faq-item.active .faq-content {
            display: block;
        }
        .faq-item.active .faq-title i {
            transform: rotate(180deg);
            color: #0f172a;
        }

        /* --- Contact Section --- */
        .contact-section {
            background-color: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .contact-section h2 {
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 25px;
            color: #0f172a;
        }
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .contact-card {
            padding: 20px;
            background-color: #f9fafb;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #f3f4f6;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }
        .contact-card:hover {
            background-color: #f3f4f6;
            border-color: #d1d5db;
        }
        .contact-card i {
            font-size: 24px;
            color: #0f172a;
            margin-bottom: 12px;
        }
        .contact-card h4 {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .contact-card p {
            font-size: 13px;
            color: #6b7280;
        }

        /* --- Footer --- */
        .footer {
            text-align: center;
            padding: 40px 20px;
            background-color: #ffffff;
            margin-top: 60px;
            border-top: 1px solid #e5e7eb;
        }
        .footer-text { font-size: 12px; font-weight: 700; color: #6b7280; }
    </style>
</head>
<body>

    <header>
        <div class="logo-section">
            <img src="img/logo1.png" alt="Peer Solution">
            <h1>HELP CENTER</h1>
        </div>
        <a href="index.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
    </header>

    <div class="container">
        <h2 class="section-title">How can we help you?</h2>

        <div class="faq-section">
            <h2><i class="fa-solid fa-circle-question"></i> Frequently Asked Questions</h2>
            
            <div class="faq-item">
                <div class="faq-title">
                    <h3>How do I reset my password?</h3>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="faq-content">
                    To reset your password, go to the login page and click on "Forget Password?". Enter your registered User ID, and follow the instructions sent to your email.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-title">
                    <h3>I am unable to log in, what should I do?</h3>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="faq-content">
                    Please make sure your User ID and password are correct. If you still cannot log in, your account might be inactive. Contact your Manager or Super Admin for further assistance.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-title">
                    <h3>How can an Agent update lead status?</h3>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="faq-content">
                    Log in to the Agent Dashboard, go to the "Leads" section, select the lead you are working on, and update the status from the dropdown menu.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-title">
                    <h3>Is my data secured?</h3>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="faq-content">
                    Yes, all your data and passwords are highly encrypted. We maintain the best security practices to keep your CRM data protected.
                </div>
            </div>
        </div>

        <div class="contact-section">
            <h2><i class="fa-solid fa-headset"></i> Still need help? Contact Us</h2>
            <div class="contact-grid">
                <a href="mailto:support@peersolutionbpo.com" class="contact-card">
                    <i class="fa-solid fa-envelope"></i>
                    <h4>Email Support</h4>
                    <p>support@peersolutionbpo.com</p>
                </a>
                <a href="https://wa.me/yournumber" target="_blank" class="contact-card">
                    <i class="fa-brands fa-whatsapp"></i>
                    <h4>WhatsApp</h4>
                    <p>Live Chat Support</p>
                </a>
                <div class="contact-card">
                    <i class="fa-solid fa-clock"></i>
                    <h4>Working Hours</h4>
                    <p>Mon - Sat (10AM - 7PM)</p>
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        <div class="footer-text">By Peer Solution With <span style="color: #ef4444; font-size: 14px;">❤️</span></div>
    </div>

    <script>
        // FAQ Accordion Script
        const faqItems = document.querySelectorAll('.faq-item');

        faqItems.forEach(item => {
            const title = item.querySelector('.faq-title');
            title.addEventListener('click', () => {
                // Close other open items
                faqItems.forEach(otherItem => {
                    if (otherItem !== item && otherItem.classList.contains('active')) {
                        otherItem.classList.remove('active');
                    }
                });
                // Toggle current item
                item.classList.toggle('active');
            });
        });
    </script>
</body>
</html>