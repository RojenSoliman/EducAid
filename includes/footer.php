<?php
// filepath: c:\xampp\htdocs\EducAid\includes\footer.php
?>
<!-- ULTRA-COMPACT FOOTER - CENTERED WITH CUSTOM LOGO -->
<footer class="ultra-compact-footer bg-dark text-light py-2 mt-3">
    <div class="container-fluid">
        <div class="d-flex justify-content-center align-items-center flex-wrap text-center">
            <!-- Brand with Custom Logo -->
            <div class="footer-brand d-flex align-items-center me-4">
                <img src="<?php echo $logo_path ?? '../../assets/images/educaid-logo.png'; ?>" 
                     alt="EducAid Logo" 
                     class="footer-logo me-2">
                <span style="font-size: 0.9rem; color: white;">
                    <strong>EducAid</strong>
                    <small class="ms-1" style="color: white;">General Trias</small>
                </span>
            </div>
            
            <!-- Links -->
            <div class="footer-links d-flex align-items-center me-4">
                <a href="<?php echo $footer_login_url ?? '../../unified_login.php'; ?>" class="text-light text-decoration-none me-3 footer-link" title="Login">
                    <i class="bi bi-box-arrow-in-right"></i>
                </a>
                <a href="#" class="text-light text-decoration-none me-3 footer-link" title="Help">
                    <i class="bi bi-question-circle"></i>
                </a>
                <a href="#" class="text-light text-decoration-none footer-link" title="Contact">
                    <i class="bi bi-telephone"></i>
                </a>
            </div>
            
            <!-- Copyright -->
            <small style="font-size: 0.75rem; color: white;">
                © <?php echo date('Y'); ?> EducAid
                <i class="bi bi-shield-lock-fill ms-2" style="font-size: 0.7rem; color: white;"></i>
            </small>
        </div>
    </div>
</footer>

<style>
.ultra-compact-footer {
    background: #2c3e50 !important;
    border-top: 1px solid #28a745;
    font-size: 0.8rem;
    min-height: 45px !important;
    max-height: 45px !important;
    padding: 0.5rem 0 !important;
    color: white !important;
}

.ultra-compact-footer .footer-logo {
    height: 28px;
    width: auto;
    max-width: 28px;
    object-fit: contain;
    filter: brightness(1.1); /* Slightly brighten the logo for dark background */
}

.ultra-compact-footer * {
    color: white !important;
}

.ultra-compact-footer .text-muted {
    color: white !important;
}

.ultra-compact-footer .footer-link {
    transition: color 0.2s ease;
    padding: 0.25rem;
    font-size: 1rem;
    color: white !important;
}

.ultra-compact-footer .footer-link:hover {
    color: #28a745 !important;
}

.ultra-compact-footer .footer-brand {
    white-space: nowrap;
    color: white !important;
}

.ultra-compact-footer .footer-brand span,
.ultra-compact-footer .footer-brand small {
    color: white !important;
}

/* Mobile - Everything centered and stacked */
@media (max-width: 768px) {
    .ultra-compact-footer {
        min-height: 60px !important;
        max-height: 60px !important;
    }
    
    .ultra-compact-footer .footer-logo {
        height: 24px;
        max-width: 24px;
    }
    
    .ultra-compact-footer .d-flex {
        flex-direction: column;
        gap: 0.25rem;
        text-align: center;
    }
    
    .ultra-compact-footer .footer-brand,
    .ultra-compact-footer .footer-links {
        margin-right: 0 !important;
    }
    
    .ultra-compact-footer .footer-brand {
        font-size: 0.8rem;
    }
    
    .ultra-compact-footer .footer-links {
        gap: 1rem;
        justify-content: center;
    }
    
    .ultra-compact-footer small {
        display: none; /* Hide copyright on mobile to save space */
    }
}

/* Very small screens */
@media (max-width: 480px) {
    .ultra-compact-footer {
        min-height: 50px !important;
        max-height: 50px !important;
    }
    
    .ultra-compact-footer .footer-logo {
        height: 20px;
        max-width: 20px;
    }
    
    .ultra-compact-footer .footer-brand small {
        display: none;
    }
}
</style>