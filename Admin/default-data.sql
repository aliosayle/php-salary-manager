-- Insert default permissions
INSERT INTO `permissions` (`id`, `action`, `description`) VALUES
(UUID(), 'view_dashboard', 'Access to view the dashboard'),
(UUID(), 'manage_users', 'Ability to create, edit, and delete users'),
(UUID(), 'manage_roles', 'Ability to create, edit, and delete roles and permissions'),
(UUID(), 'view_employees', 'Access to view employee records'),
(UUID(), 'manage_employees', 'Ability to create, edit, and delete employee records'),
(UUID(), 'view_education', 'Access to view education records'),
(UUID(), 'manage_education', 'Ability to create, edit, and delete education records'),
(UUID(), 'view_posts', 'Access to view job posts'),
(UUID(), 'manage_posts', 'Ability to create, edit, and delete job posts'),
(UUID(), 'generate_reports', 'Ability to generate and export reports');

-- Create Administrator role
INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(UUID(), 'Administrator', 'Full system access');

-- Assign permissions to Administrator role
-- First, get the role ID
SET @admin_role_id = (SELECT `id` FROM `roles` WHERE `name` = 'Administrator' LIMIT 1);

-- Then get the permission IDs and insert role_permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, `id` FROM `permissions`;

-- Create default admin user
-- Password is 'admin123' - Change this immediately after setup!
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role_id`, `is_active`, `created_at`, `updated_at`) VALUES
(UUID(), 'admin', 'admin@example.com', '$2y$12$xE2V0KXbNGODu6OXzvY5W.dO4NoMy9lLrWJYlQJPqeXcZzFWcCWZ2', @admin_role_id, 1, NOW(), NOW()); 