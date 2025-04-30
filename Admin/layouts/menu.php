<?php
// Get the current file name
$filename = basename($_SERVER['PHP_SELF']);

// Add inline translations for menu items
$menu_translations = [
    'en' => [
        'dashboard' => 'Dashboard',
        'hr_management' => 'HR Management',
        'employees' => 'Employees',
        'employee_list' => 'Employee List',
        'shop_employees' => 'Shop Employees',
        'performance' => 'Performance',
        'employee_evaluations' => 'Employee Evaluations',
        'batch_evaluations' => 'Batch Evaluations',
        'assistant_manager_evaluations' => 'Assistant Manager Evaluations',
        'assistant_manager_batch' => 'Assistant Manager Batch',
        'financial_management' => 'Financial Management',
        'sales_management' => 'Sales Management',
        'monthly_sales' => 'Monthly Sales',
        'manager_debts' => 'Manager Debts',
        'bonus_management' => 'Bonus Management',
        'bonus_configuration' => 'Bonus Configuration',
        'evaluation_ranges' => 'Evaluation Ranges',
        'manager_bonus' => 'Manager Bonus',
        'reports' => 'Reports',
        'salary_report' => 'Salary Report',
        'assistant_manager_salary' => 'Assistant Manager Salary',
        'store_management_report' => 'Store Management Report',
        'manager_bonus_report' => 'Manager Bonus Report',
        'system_settings' => 'System Settings',
        'reference_data' => 'Reference Data',
        'shops' => 'Shops',
        'positions' => 'Positions',
        'education_levels' => 'Education Levels',
        'recommenders' => 'Recommenders',
        'month_management' => 'Month Management',
        'currencies' => 'Currencies',
        'administration' => 'Administration',
        'user_management' => 'User Management',
        'all_users' => 'All Users',
        'roles' => 'Roles',
        'permissions' => 'Permissions',
        'account' => 'Account',
        'profile' => 'Profile',
        'logout' => 'Logout'
    ],
    'fr' => [
        'dashboard' => 'Tableau de Bord',
        'hr_management' => 'Gestion RH',
        'employees' => 'Employés',
        'employee_list' => 'Liste des Employés',
        'shop_employees' => 'Employés de Magasin',
        'performance' => 'Performance',
        'employee_evaluations' => 'Évaluations des Employés',
        'batch_evaluations' => 'Évaluations par Lots',
        'assistant_manager_evaluations' => 'Évaluations des Directeurs Adjoints',
        'assistant_manager_batch' => 'Lots d\'Évaluations des Directeurs Adjoints',
        'financial_management' => 'Gestion Financière',
        'sales_management' => 'Gestion des Ventes',
        'monthly_sales' => 'Ventes Mensuelles',
        'manager_debts' => 'Dettes des Managers',
        'bonus_management' => 'Gestion des Primes',
        'bonus_configuration' => 'Configuration des Primes',
        'evaluation_ranges' => 'Plages d\'Évaluation',
        'manager_bonus' => 'Primes des Managers',
        'reports' => 'Rapports',
        'salary_report' => 'Rapport des Salaires',
        'assistant_manager_salary' => 'Salaire des Directeurs Adjoints',
        'store_management_report' => 'Rapport de Gestion des Magasins',
        'manager_bonus_report' => 'Rapport des Primes des Managers',
        'system_settings' => 'Paramètres Système',
        'reference_data' => 'Données de Référence',
        'shops' => 'Magasins',
        'positions' => 'Postes',
        'education_levels' => 'Niveaux d\'Éducation',
        'recommenders' => 'Recommandateurs',
        'month_management' => 'Gestion des Mois',
        'currencies' => 'Devises',
        'administration' => 'Administration',
        'user_management' => 'Gestion des Utilisateurs',
        'all_users' => 'Tous les Utilisateurs',
        'roles' => 'Rôles',
        'permissions' => 'Permissions',
        'account' => 'Compte',
        'profile' => 'Profil',
        'logout' => 'Déconnexion'
    ]
];

// Helper function to override the global translation function just for menu items
function menu_translate($key) {
    global $menu_translations;
    $lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en';
    
    if (isset($menu_translations[$lang][$key])) {
        return $menu_translations[$lang][$key];
    }
    
    // Fall back to the global translation function
    return __($key);
}
?>

<!-- ========== Left Sidebar Start ========== -->
<div class="vertical-menu">

    <div data-simplebar class="h-100">

        <!--- Sidemenu -->
        <div id="sidebar-menu">
            <!-- Left Menu Start -->
            <ul class="metismenu list-unstyled" id="side-menu">
                <!-- Dashboard - Always shown to all users -->
                <li>
                    <a href="index.php" class="waves-effect <?php echo $filename == 'index.php' ? 'active' : ''; ?>">
                        <i class="bx bx-home-circle"></i>
                        <span><?php echo menu_translate('dashboard'); ?></span>
                    </a>
                </li>

                <!-- Employee Management Section -->
                <?php if (hasPermission('view_employees')): ?>
                <li class="menu-title"><?php echo menu_translate('hr_management'); ?></li>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-user-circle"></i>
                        <span><?php echo menu_translate('employees'); ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="employees.php" class="<?php echo $filename == 'employees.php' ? 'active' : ''; ?>">
                            <i class="bx bx-list-ul me-1"></i><?php echo menu_translate('employee_list'); ?></a>
                        </li>
                        <li><a href="shop-employees.php" class="<?php echo $filename == 'shop-employees.php' ? 'active' : ''; ?>">
                            <i class="bx bx-group me-1"></i><?php echo menu_translate('shop_employees'); ?></a>
                        </li>
                    </ul>
                </li>
                
                <!-- Performance & Evaluations Section -->
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-star"></i>
                        <span><?php echo menu_translate('performance'); ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="employee-evaluations.php" class="<?php echo $filename == 'employee-evaluations.php' ? 'active' : ''; ?>">
                            <i class="bx bx-check-square me-1"></i><?php echo menu_translate('employee_evaluations'); ?></a>
                        </li>
                        <li><a href="batch-evaluations.php" class="<?php echo $filename == 'batch-evaluations.php' ? 'active' : ''; ?>">
                            <i class="bx bx-grid me-1"></i><?php echo menu_translate('batch_evaluations'); ?></a>
                        </li>
                        <?php if (hasPermission('view_assistant_manager_evaluations')): ?>
                        <li><a href="assistant-managers-evaluations.php" class="<?php echo $filename == 'assistant-managers-evaluations.php' ? 'active' : ''; ?>">
                            <i class="bx bx-user-check me-1"></i><?php echo menu_translate('assistant_manager_evaluations'); ?></a>
                        </li>
                        <li><a href="assistant-managers-batch-evaluations.php" class="<?php echo $filename == 'assistant-managers-batch-evaluations.php' ? 'active' : ''; ?>">
                            <i class="bx bx-grid-alt me-1"></i><?php echo menu_translate('assistant_manager_batch'); ?></a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Sales & Bonus Management Section -->
                <?php if (hasPermission('manage_monthly_sales') || hasPermission('manage_bonus_configuration')): ?>
                <li class="menu-title"><?php echo menu_translate('financial_management'); ?></li>
                
                <?php if (hasPermission('manage_monthly_sales')): ?>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-line-chart"></i>
                        <span><?php echo menu_translate('sales_management'); ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="monthly-sales.php" class="<?php echo $filename == 'monthly-sales.php' ? 'active' : ''; ?>">
                            <i class="bx bx-chart me-1"></i><?php echo menu_translate('monthly_sales'); ?></a>
                        </li>
                        <?php if (hasPermission('manage_manager_debts')): ?>
                        <li><a href="manager-debts.php" class="<?php echo $filename == 'manager-debts.php' ? 'active' : ''; ?>">
                            <i class="bx bx-money me-1"></i><?php echo menu_translate('manager_debts'); ?></a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('manage_bonus_configuration')): ?>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-award"></i>
                        <span><?php echo menu_translate('bonus_management'); ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="bonus-configuration.php" class="<?php echo $filename == 'bonus-configuration.php' ? 'active' : ''; ?>">
                            <i class="bx bx-cog me-1"></i><?php echo menu_translate('bonus_configuration'); ?></a>
                        </li>
                        <li><a href="total-ranges.php" class="<?php echo $filename == 'total-ranges.php' ? 'active' : ''; ?>">
                            <i class="bx bx-slider me-1"></i><?php echo menu_translate('evaluation_ranges'); ?></a>
                        </li>
                        <li><a href="manager-bonus.php" class="<?php echo $filename == 'manager-bonus.php' ? 'active' : ''; ?>">
                            <i class="bx bx-gift me-1"></i><?php echo menu_translate('manager_bonus'); ?></a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Reports Section -->
                <?php if (hasPermission('view_reports')): ?>
                <li class="menu-title"><?php echo menu_translate('reports'); ?></li>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bxs-report"></i>
                        <span><?php echo menu_translate('reports'); ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="salary-report.php" class="<?php echo $filename == 'salary-report.php' ? 'active' : ''; ?>">
                            <i class="bx bx-dollar-circle me-1"></i><?php echo menu_translate('salary_report'); ?></a>
                        </li>
                        <li><a href="assistant-manager-salary-report.php" class="<?php echo $filename == 'assistant-manager-salary-report.php' ? 'active' : ''; ?>">
                            <i class="bx bx-receipt me-1"></i><?php echo menu_translate('assistant_manager_salary'); ?></a>
                        </li>
                        <li><a href="store-management-report.php" class="<?php echo $filename == 'store-management-report.php' ? 'active' : ''; ?>">
                            <i class="bx bx-store-alt me-1"></i><?php echo menu_translate('store_management_report'); ?></a>
                        </li>
                        <li><a href="manager-bonus-report.php" class="<?php echo $filename == 'manager-bonus-report.php' ? 'active' : ''; ?>">
                            <i class="bx bx-bar-chart-alt-2 me-1"></i><?php echo menu_translate('manager_bonus_report'); ?></a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>
                
                <!-- Reference Data Section -->
                <?php 
                $showReferenceData = hasPermission('manage_recommenders') || 
                                     hasPermission('manage_education_levels') || 
                                     hasPermission('manage_posts') || 
                                     hasPermission('manage_shops');
                
                if ($showReferenceData): 
                ?>
                <li class="menu-title"><?php echo menu_translate('system_settings'); ?></li>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-list-ul"></i>
                        <span><?php echo menu_translate('reference_data'); ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <?php if (hasPermission('manage_shops')): ?>
                        <li><a href="shops.php" class="<?php echo $filename == 'shops.php' ? 'active' : ''; ?>">
                            <i class="bx bx-store me-1"></i><?php echo menu_translate('shops'); ?></a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('manage_posts')): ?>
                        <li><a href="posts.php" class="<?php echo $filename == 'posts.php' ? 'active' : ''; ?>">
                            <i class="bx bx-briefcase me-1"></i><?php echo menu_translate('positions'); ?></a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('manage_education_levels')): ?>
                        <li><a href="education-levels.php" class="<?php echo $filename == 'education-levels.php' ? 'active' : ''; ?>">
                            <i class="bx bx-graduation me-1"></i><?php echo menu_translate('education_levels'); ?></a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('manage_recommenders')): ?>
                        <li><a href="recommenders.php" class="<?php echo $filename == 'recommenders.php' ? 'active' : ''; ?>">
                            <i class="bx bx-user-plus me-1"></i><?php echo menu_translate('recommenders'); ?></a>
                        </li>
                        <?php endif; ?>

                        <?php if (hasPermission('manage_settings')): ?>
                        <li><a href="months.php" class="<?php echo $filename == 'months.php' ? 'active' : ''; ?>">
                            <i class="bx bx-calendar me-1"></i><?php echo menu_translate('month_management'); ?></a>
                        </li>
                        <li><a href="currencies.php" class="<?php echo $filename == 'currencies.php' ? 'active' : ''; ?>">
                            <i class="bx bx-dollar me-1"></i><?php echo menu_translate('currencies'); ?></a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Administration Section -->
                <?php 
                // Check if user is an admin
                $is_admin = false;
                if (isset($_SESSION['role_id'])) {
                    try {
                        $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
                        $stmt->execute([$_SESSION['role_id']]);
                        $role = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($role && ($role['name'] === 'Administrator' || $role['id'] == 1)) {
                            $is_admin = true;
                        }
                    } catch (Exception $e) {
                        // Silent exception handling
                    }
                }
                
                if ($is_admin): 
                ?>
                <li class="menu-title"><?php echo menu_translate('administration'); ?></li>

                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-user-check"></i>
                        <span><?php echo menu_translate('user_management'); ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="users.php" class="<?php echo $filename == 'users.php' ? 'active' : ''; ?>">
                            <i class="bx bx-user me-1"></i><?php echo menu_translate('all_users'); ?></a>
                        </li>
                        <?php if (hasPermission('manage_roles')): ?>
                        <li><a href="roles.php" class="<?php echo $filename == 'roles.php' ? 'active' : ''; ?>">
                            <i class="bx bx-user-voice me-1"></i><?php echo menu_translate('roles'); ?></a>
                        </li>
                        <li><a href="permissions.php" class="<?php echo $filename == 'permissions.php' ? 'active' : ''; ?>">
                            <i class="bx bx-lock-alt me-1"></i><?php echo menu_translate('permissions'); ?></a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- User Account Section - Always shown -->
                <li class="menu-title"><?php echo menu_translate('account'); ?></li>

                <li>
                    <a href="profile.php" class="waves-effect <?php echo $filename == 'profile.php' ? 'active' : ''; ?>">
                        <i class="bx bx-user"></i>
                        <span><?php echo menu_translate('profile'); ?></span>
                    </a>
                </li>

                <li>
                    <a href="logout.php" class="waves-effect">
                        <i class="bx bx-log-out"></i>
                        <span><?php echo menu_translate('logout'); ?></span>
                    </a>
                </li>
            </ul>
        </div>
        <!-- Sidebar -->
    </div>
</div>
<!-- Left Sidebar End -->