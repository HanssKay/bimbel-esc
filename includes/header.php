<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<header class="sticky top-0 z-50 bg-white shadow-md">
    <nav class="container mx-auto px-6 py-4">
        <div class="flex justify-between items-center">
            <!-- Logo -->
            <div class="flex items-center">
                <div class="bg-blue-600 text-white w-10 h-10 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-graduation-cap text-xl"></i>
                </div>
                <a href="index.php" class="text-2xl font-bold text-gray-800">
                    ESC<span class="text-blue-600 ms-1">PrivateEducation</span>
                </a>
            </div>

            <!-- Desktop Navigation -->
            <div class="hidden md:flex items-center space-x-8">
                <a href="index.php"
                    class="<?php echo ($current_page == 'index.php') ? 'text-blue-600 font-semibold' : 'text-gray-700 hover:text-blue-600'; ?>">
                    <i class="fas fa-home mr-1"></i> Home
                </a>

                <a href="pages/about.php"
                    class="<?php echo ($current_page == 'about.php') ? 'text-blue-600 font-semibold' : 'text-gray-700 hover:text-blue-600'; ?>">
                    <i class="fas fa-info-circle mr-1"></i> Tentang Kami
                </a>

                <a href="pages/contact.php"
                    class="<?php echo ($current_page == 'contact.php') ? 'text-blue-600 font-semibold' : 'text-gray-700 hover:text-blue-600'; ?>">
                    <i class="fas fa-envelope mr-1"></i> Kontak
                </a>

                <a href="auth/login.php"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-6 rounded-lg transition duration-300">
                    <i class="fas fa-sign-in-alt mr-2"></i> Login
                </a>
            </div>

            <!-- Mobile menu button -->
            <button id="mobile-menu-button" class="md:hidden text-gray-700">
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>

        <!-- Mobile Navigation -->
        <div id="mobile-menu" class="hidden md:hidden mt-4 pb-4">
            <div class="flex flex-col space-y-3">
                <a href="index.php"
                    class="text-gray-700 hover:text-blue-600 py-2 px-4 rounded <?php echo ($current_page == 'index.php') ? 'bg-blue-50 text-blue-600' : ''; ?>">
                    <i class="fas fa-home mr-2"></i> Home
                </a>

                <a href="pages/about.php"
                    class="text-gray-700 hover:text-blue-600 py-2 px-4 rounded <?php echo ($current_page == 'about.php') ? 'bg-blue-50 text-blue-600' : ''; ?>">
                    <i class="fas fa-info-circle mr-2"></i> Tentang Kami
                </a>

                <a href="pages/contact.php"
                    class="text-gray-700 hover:text-blue-600 py-2 px-4 rounded <?php echo ($current_page == 'contact.php') ? 'bg-blue-50 text-blue-600' : ''; ?>">
                    <i class="fas fa-envelope mr-2"></i> Kontak
                </a>

                <a href="auth/login.php"
                    class="bg-blue-600 text-white font-semibold py-3 px-4 rounded-lg text-center mt-2">
                    <i class="fas fa-sign-in-alt mr-2"></i> Login
                </a>
            </div>
        </div>
    </nav>
</header>

<!-- JavaScript untuk Mobile Menu -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');

        if (mobileMenuButton) {
            mobileMenuButton.addEventListener('click', function () {
                mobileMenu.classList.toggle('hidden');

                // Change icon
                const icon = mobileMenuButton.querySelector('i');
                if (mobileMenu.classList.contains('hidden')) {
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                } else {
                    icon.classList.remove('fa-bars');
                    icon.classList.add('fa-times');
                }
            });
        }
    });
</script>