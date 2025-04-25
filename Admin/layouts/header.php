<?php
// All header.php code below this line will be kept - removed dataset check logic
?>
<header id="page-topbar">
    <div class="navbar-header">
        <div class="d-flex">
            <!-- LOGO -->
            <div class="navbar-brand-box">
                <a href="index.php" class="logo logo-dark">
                    <span class="logo-sm">
                        <img src="assets/images/logo-sm.svg" alt="" height="24">
                    </span>
                    <span class="logo-lg">
                        <img src="assets/images/logo-sm.svg" alt="" height="24"> <span class="logo-txt">EmpManager</span>
                    </span>
                </a>

                <a href="index.php" class="logo logo-light">
                    <span class="logo-sm">
                        <img src="assets/images/logo-sm.svg" alt="" height="24">
                    </span>
                    <span class="logo-lg">
                        <img src="assets/images/logo-sm.svg" alt="" height="24"> <span class="logo-txt">EmpManager</span>
                    </span>
                </a>
            </div>

            <button type="button" class="btn btn-sm px-3 font-size-16 header-item" id="vertical-menu-btn">
                <i class="fa fa-fw fa-bars"></i>
            </button>

            <!-- Department and Dataset selectors removed -->
        </div>

        <div class="d-flex">
            <div class="dropdown d-inline-block d-lg-none ms-2">
                <button type="button" class="btn header-item" id="page-header-search-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i data-feather="search" class="icon-lg"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end p-0" aria-labelledby="page-header-search-dropdown">
                    <form class="p-3">
                        <div class="form-group m-0">
                            <div class="input-group">
                                <input type="text" class="form-control" placeholder="Search..." aria-label="Search Result">
                                <button class="btn btn-primary" type="submit"><i class="mdi mdi-magnify"></i></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Language Selector -->
            <div class="dropdown d-inline-block">
                <button type="button" class="btn header-item" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <?php 
                    $current_lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en';
                    if ($current_lang == 'fr') {
                        echo '<img src="assets/images/flags/french.jpg" alt="French Flag" height="16">';
                        echo '<span class="ms-1 d-none d-sm-inline-block">Français</span>';
                    } else {
                        echo '<img src="assets/images/flags/us.jpg" alt="US Flag" height="16">';
                        echo '<span class="ms-1 d-none d-sm-inline-block">English</span>';
                    }
                    ?>
                    <i class="mdi mdi-chevron-down d-none d-sm-inline-block"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    <a href="<?php echo (strpos($_SERVER['PHP_SELF'], '/Admin/') !== false) ? 'layouts/set-language.php' : 'Admin/layouts/set-language.php'; ?>?lang=en&redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="dropdown-item notify-item <?php echo $current_lang == 'en' ? 'active' : ''; ?>">
                        <img src="assets/images/flags/us.jpg" alt="US Flag" class="me-1" height="12">
                        <span class="align-middle">English</span>
                    </a>
                    <a href="<?php echo (strpos($_SERVER['PHP_SELF'], '/Admin/') !== false) ? 'layouts/set-language.php' : 'Admin/layouts/set-language.php'; ?>?lang=fr&redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="dropdown-item notify-item <?php echo $current_lang == 'fr' ? 'active' : ''; ?>">
                        <img src="assets/images/flags/french.jpg" alt="French Flag" class="me-1" height="12">
                        <span class="align-middle">Français</span>
                    </a>
                </div>
            </div>

            <!-- User Profile -->
            <div class="dropdown d-inline-block">
                <button type="button" class="btn header-item" id="page-header-user-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <img class="rounded-circle header-profile-user" src="assets/images/users/avatar-1.jpg" alt="Header Avatar">
                    <span class="d-none d-xl-inline-block ms-1 fw-medium">
                        <?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : 'Admin'; ?>
                    </span>
                    <i class="mdi mdi-chevron-down d-none d-xl-inline-block"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    <!-- item-->
                    <a class="dropdown-item" href="user-profile.php"><i class="mdi mdi-face-profile font-size-16 align-middle me-1"></i> <?php echo __('header_my_profile'); ?></a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="logout.php"><i class="mdi mdi-logout font-size-16 align-middle me-1"></i> <?php echo __('header_logout'); ?></a>
                </div>
            </div>

        </div>
    </div>
</header> 