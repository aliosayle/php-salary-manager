<?php
// Get the current file name
$filename = basename($_SERVER['PHP_SELF']);
?>

<!-- ========== Left Sidebar Start ========== -->
<div class="vertical-menu">

    <div data-simplebar class="h-100">

        <!--- Sidemenu -->
        <div id="sidebar-menu">
            <!-- Left Menu Start -->
            <ul class="metismenu list-unstyled" id="side-menu">
                <li class="menu-title"><?php echo $_SESSION['lang'] == 'fr' ? 'Menu' : 'Menu'; ?></li>

                <li>
                    <a href="index.php" class="waves-effect">
                        <i class="bx bx-home-circle"></i>
                        <span><?php echo $_SESSION['lang'] == 'fr' ? 'Tableau de Bord' : 'Dashboard'; ?></span>
                    </a>
                </li>

                <!-- Show admin section - Check both role_id and admin permissions -->
                <?php 
                // Get current role from database to ensure it's valid and verify it's an admin role
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
                <li class="menu-title"><?php echo $_SESSION['lang'] == 'fr' ? 'Administration' : 'Administration'; ?></li>

                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-user"></i>
                        <span><?php echo $_SESSION['lang'] == 'fr' ? 'Utilisateurs' : 'Users'; ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="users.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Tous les Utilisateurs' : 'All Users'; ?></a></li>
                    </ul>
                </li>

                <?php if (hasPermission('manage_roles')): ?>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-shield"></i>
                        <span><?php echo $_SESSION['lang'] == 'fr' ? 'Rôles et Permissions' : 'Roles and Permissions'; ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="roles.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Rôles' : 'Roles'; ?></a></li>
                        <li><a href="permissions.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Permissions' : 'Permissions'; ?></a></li>
                    </ul>
                </li>
                <?php endif; ?>
                <?php endif; ?>

                <li class="menu-title"><?php echo $_SESSION['lang'] == 'fr' ? 'Gestion des Employés' : 'Employee Management'; ?></li>

                <?php if (hasPermission('view_employees')): ?>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-user-circle"></i>
                        <span><?php echo $_SESSION['lang'] == 'fr' ? 'Employés' : 'Employees'; ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="employees.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Tous les Employés' : 'All Employees'; ?></a></li>
                        <li><a href="employee-evaluations.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Évaluations des Employés' : 'Employee Evaluations'; ?></a></li>
                        <?php if (hasPermission('view_assistant_manager_evaluations')): ?>
                        <li><a href="assistant-managers-evaluations.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Évaluations des Adjoints' : 'Assistant Manager Evaluations'; ?></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('manage_monthly_sales') || hasPermission('manage_bonus_configuration')): ?>
                <li class="menu-title"><?php echo $_SESSION['lang'] == 'fr' ? 'Gestion des Bonus' : 'Bonus Management'; ?></li>

                <?php if (hasPermission('manage_monthly_sales')): ?>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-line-chart"></i>
                        <span><?php echo $_SESSION['lang'] == 'fr' ? 'Ventes Mensuelles' : 'Monthly Sales'; ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="monthly-sales.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Gestion des Ventes' : 'Sales Management'; ?></a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('manage_bonus_configuration')): ?>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-cog"></i>
                        <span><?php echo $_SESSION['lang'] == 'fr' ? 'Configuration des Bonus' : 'Bonus Configuration'; ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="bonus-configuration.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Configuration des Bonus' : 'Bonus Configuration'; ?></a></li>
                    </ul>
                </li>
                <?php endif; ?>
                <?php endif; ?>

                <?php 
                // Check if user has permission to manage either reference data item
                $showReferenceData = hasPermission('manage_recommenders') || 
                                     hasPermission('manage_education_levels') || 
                                     hasPermission('manage_posts') || 
                                     hasPermission('manage_shops');
                
                if ($showReferenceData): 
                ?>
                <li class="menu-title"><?php echo $_SESSION['lang'] == 'fr' ? 'Données de Référence' : 'Reference Data'; ?></li>

                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-list-ul"></i>
                        <span><?php echo $_SESSION['lang'] == 'fr' ? 'Données de Référence' : 'Reference Data'; ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <?php if (hasPermission('manage_posts')): ?>
                        <li><a href="posts.php"><i class="bx bx-briefcase me-1"></i><?php echo $_SESSION['lang'] == 'fr' ? 'Postes' : 'Positions'; ?></a></li>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('manage_shops')): ?>
                        <li><a href="shops.php"><i class="bx bx-store me-1"></i><?php echo $_SESSION['lang'] == 'fr' ? 'Magasins' : 'Shops'; ?></a></li>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('manage_recommenders')): ?>
                        <li><a href="recommenders.php"><i class="bx bx-user-plus me-1"></i><?php echo $_SESSION['lang'] == 'fr' ? 'Recommandeurs' : 'Recommenders'; ?></a></li>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('manage_education_levels')): ?>
                        <li><a href="education-levels.php"><i class="bx bx-graduation me-1"></i><?php echo $_SESSION['lang'] == 'fr' ? 'Niveaux d\'Éducation' : 'Education Levels'; ?></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <li class="menu-title"><?php echo $_SESSION['lang'] == 'fr' ? 'Rapports et Paramètres' : 'Reports and Settings'; ?></li>

                <?php if (hasPermission('view_reports')): ?>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-file"></i>
                        <span><?php echo $_SESSION['lang'] == 'fr' ? 'Rapports' : 'Reports'; ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="employee-report.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Rapport d\'Employés' : 'Employee Report'; ?></a></li>
                        <li><a href="salary-report.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Rapport de Salaires' : 'Salary Report'; ?></a></li>
                        <li><a href="store-management-report.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Rapport de Gestion des Magasins' : 'Store Management Report'; ?></a></li>
                        <?php if (hasPermission('manage_manager_debts')): ?>
                        <li><a href="manager-debts.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Dettes des Gérants' : 'Manager Debts'; ?></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('manage_settings')): ?>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="bx bx-cog"></i>
                        <span><?php echo $_SESSION['lang'] == 'fr' ? 'Paramètres' : 'Settings'; ?></span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="settings.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Paramètres Généraux' : 'General Settings'; ?></a></li>
                        <li><a href="total-ranges.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Plages Totales' : 'Total Ranges'; ?></a></li>
                        <li><a href="months.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Gestion des Mois' : 'Month Management'; ?></a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <li class="menu-title"><?php echo __('account'); ?></li>

                <li>
                    <a href="profile.php" class="waves-effect">
                        <i class="bx bx-user"></i>
                        <span><?php echo __('profile'); ?></span>
                    </a>
                </li>

                <li>
                    <a href="logout.php" class="waves-effect">
                        <i class="bx bx-log-out"></i>
                        <span><?php echo __('logout'); ?></span>
                    </a>
                </li>
            </ul>
        </div>
        <!-- Sidebar -->
    </div>
</div>
<!-- Left Sidebar End -->