-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Tempo de geração: 28/12/2025 às 00:11
-- Versão do servidor: 10.4.27-MariaDB
-- Versão do PHP: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `gelo`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `deposits`
--

CREATE TABLE `deposits` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(160) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `deposits`
--

INSERT INTO `deposits` (`id`, `title`, `phone`, `address`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'DEPOSITO DE GELO', '98991668283', 'GERONCIO BRIGIDO NETO 187', 1, '2025-12-27 17:25:40', '2025-12-27 17:25:40');

-- --------------------------------------------------------

--
-- Estrutura para tabela `products`
--

CREATE TABLE `products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(160) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `products`
--

INSERT INTO `products` (`id`, `title`, `unit_price`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Escama de 5 kg', '4.30', 1, '2025-12-27 17:25:11', '2025-12-27 18:29:40'),
(2, 'Escama de 10 kg', '4.90', 1, '2025-12-27 18:30:10', '2025-12-27 18:30:10'),
(3, 'Escama de 20 kg', '8.10', 1, '2025-12-27 18:30:20', '2025-12-27 18:30:20'),
(4, 'Tubo de 20 kg', '8.50', 1, '2025-12-27 18:30:31', '2025-12-27 18:30:31'),
(5, 'Tubo de 10 kg', '5.00', 1, '2025-12-27 18:30:42', '2025-12-27 18:30:42');

-- --------------------------------------------------------

--
-- Estrutura para tabela `permission_groups`
--

CREATE TABLE `permission_groups` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `permission_groups`
--

INSERT INTO `permission_groups` (`id`, `name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Administrador', 'Acesso administrativo ao sistema', 1, '2025-12-27 16:26:32', '2025-12-27 16:26:32'),
(2, 'Usuário', 'Acesso básico para pedidos próprios', 1, '2025-12-27 16:26:32', '2025-12-27 16:26:32');

-- --------------------------------------------------------

--
-- Estrutura para tabela `permission_group_permissions`
--

CREATE TABLE `permission_group_permissions` (
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `permission_key` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `permission_group_permissions`
--

INSERT INTO `permission_group_permissions` (`group_id`, `permission_key`) VALUES
(1, 'withdrawals.access'),
(1, 'withdrawals.view_all'),
(1, 'withdrawals.create_for_client'),
(1, 'withdrawals.cancel'),
(1, 'withdrawals.separate'),
(1, 'withdrawals.deliver'),
(1, 'withdrawals.return'),
(1, 'withdrawals.pay'),
(1, 'products.access'),
(1, 'deposits.access'),
(1, 'users.access'),
(1, 'users.groups'),
(1, 'analytics.access'),
(2, 'withdrawals.access'),
(2, 'withdrawals.cancel');

-- --------------------------------------------------------

--
-- Estrutura para tabela `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `birthday` date DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('master','admin','user') NOT NULL DEFAULT 'user',
  `permission_group_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `users`
--

INSERT INTO `users` (`id`, `name`, `phone`, `birthday`, `password_hash`, `role`, `permission_group_id`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 'Yago', '98991668283', NULL, '$2y$10$U.V34eNRi6agQNbwmuUkwueerrNLyzu3SWTcSrf1y4G51/bvPoA46', 'master', 1, 1, '2025-12-27 17:26:15', '2025-12-27 16:26:32', '2025-12-27 17:26:15'),
(2, 'Pereira Augusto', '85997239941', '2025-12-27', '$2y$10$FLgllZyY1kUpk/IYoa3Abu/7pjG56F4GUzn96pwpaQmWieN03IIPW', 'user', 2, 1, NULL, '2025-12-27 17:12:21', '2025-12-27 17:12:21');

-- --------------------------------------------------------

--
-- Estrutura para tabela `withdrawal_orders`
--

CREATE TABLE `withdrawal_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('requested','separated','delivered','cancelled') NOT NULL DEFAULT 'requested',
  `comment` text DEFAULT NULL,
  `total_items` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `separated_at` datetime DEFAULT NULL,
  `separated_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `delivered_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `withdrawal_orders`
--

INSERT INTO `withdrawal_orders` (`id`, `user_id`, `created_by_user_id`, `status`, `comment`, `total_items`, `total_amount`, `separated_at`, `separated_by_user_id`, `delivered_at`, `delivered_by_user_id`, `cancelled_at`, `cancelled_by_user_id`, `cancellation_reason`, `paid_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'delivered', NULL, 70, '377.00', NULL, NULL, '2025-12-27 19:33:34', NULL, NULL, NULL, NULL, '2025-12-27 20:10:51', '2025-12-27 19:23:55', '2025-12-27 20:10:51'),
(2, 2, 1, 'cancelled', NULL, 50, '405.00', NULL, NULL, NULL, NULL, '2025-12-27 20:10:00', 1, 'Teste', NULL, '2025-12-27 19:41:25', '2025-12-27 20:10:00'),
(3, 1, 1, 'requested', NULL, 12, '51.60', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-27 20:11:13', '2025-12-27 20:11:13');

-- --------------------------------------------------------

--
-- Estrutura para tabela `withdrawal_order_items`
--

CREATE TABLE `withdrawal_order_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `product_title` varchar(160) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `line_total` decimal(10,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `withdrawal_order_items`
--

INSERT INTO `withdrawal_order_items` (`id`, `order_id`, `product_id`, `product_title`, `unit_price`, `quantity`, `line_total`, `created_at`) VALUES
(1, 1, 3, 'Escama de 20 kg', '8.10', 20, '162.00', '2025-12-27 19:23:55'),
(2, 1, 1, 'Escama de 5 kg', '4.30', 50, '215.00', '2025-12-27 19:23:55'),
(3, 2, 3, 'Escama de 20 kg', '8.10', 50, '405.00', '2025-12-27 19:41:25'),
(4, 3, 1, 'Escama de 5 kg', '4.30', 12, '51.60', '2025-12-27 20:11:13');

-- --------------------------------------------------------

--
-- Estrutura para tabela `withdrawal_payments`
--

CREATE TABLE `withdrawal_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `method` enum('pix','cash','debit','credit') NOT NULL DEFAULT 'pix',
  `paid_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `withdrawal_payments`
--

INSERT INTO `withdrawal_payments` (`id`, `order_id`, `amount`, `method`, `paid_at`, `created_by_user_id`, `note`, `created_at`) VALUES
(1, 1, '377.00', 'pix', '2025-12-27 20:10:51', 1, 'PIX', '2025-12-27 20:10:51');

-- --------------------------------------------------------

--
-- Estrutura para tabela `withdrawal_returns`
--

CREATE TABLE `withdrawal_returns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `reason` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `withdrawal_return_items`
--

CREATE TABLE `withdrawal_return_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `return_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `product_title` varchar(160) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `line_total` decimal(10,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `deposits`
--
ALTER TABLE `deposits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_deposits_active` (`is_active`),
  ADD KEY `idx_deposits_title` (`title`);

--
-- Índices de tabela `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_products_active` (`is_active`),
  ADD KEY `idx_products_title` (`title`);

--
-- Índices de tabela `permission_groups`
--
ALTER TABLE `permission_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_permission_groups_name` (`name`),
  ADD KEY `idx_permission_groups_active` (`is_active`);

--
-- Índices de tabela `permission_group_permissions`
--
ALTER TABLE `permission_group_permissions`
  ADD PRIMARY KEY (`group_id`,`permission_key`),
  ADD KEY `idx_permission_group_permissions_key` (`permission_key`);

--
-- Índices de tabela `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_users_phone` (`phone`),
  ADD KEY `idx_users_permission_group` (`permission_group_id`);

--
-- Índices de tabela `withdrawal_orders`
--
ALTER TABLE `withdrawal_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_withdrawal_orders_user` (`user_id`),
  ADD KEY `idx_withdrawal_orders_status` (`status`),
  ADD KEY `fk_withdrawal_orders_created_by` (`created_by_user_id`),
  ADD KEY `fk_withdrawal_orders_separated_by` (`separated_by_user_id`),
  ADD KEY `fk_withdrawal_orders_delivered_by` (`delivered_by_user_id`),
  ADD KEY `fk_withdrawal_orders_cancelled_by` (`cancelled_by_user_id`);

--
-- Índices de tabela `withdrawal_order_items`
--
ALTER TABLE `withdrawal_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_withdrawal_items_order` (`order_id`),
  ADD KEY `idx_withdrawal_items_product` (`product_id`);

--
-- Índices de tabela `withdrawal_payments`
--
ALTER TABLE `withdrawal_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_withdrawal_payments_order` (`order_id`),
  ADD KEY `idx_withdrawal_payments_method` (`method`),
  ADD KEY `idx_withdrawal_payments_paid_at` (`paid_at`),
  ADD KEY `fk_withdrawal_payments_created_by` (`created_by_user_id`);

--
-- Índices de tabela `withdrawal_returns`
--
ALTER TABLE `withdrawal_returns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_withdrawal_returns_order` (`order_id`),
  ADD KEY `fk_withdrawal_returns_created_by` (`created_by_user_id`);

--
-- Índices de tabela `withdrawal_return_items`
--
ALTER TABLE `withdrawal_return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_withdrawal_return_items_return` (`return_id`),
  ADD KEY `idx_withdrawal_return_items_product` (`product_id`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `deposits`
--
ALTER TABLE `deposits`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de tabela `permission_groups`
--
ALTER TABLE `permission_groups`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `withdrawal_orders`
--
ALTER TABLE `withdrawal_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `withdrawal_order_items`
--
ALTER TABLE `withdrawal_order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `withdrawal_payments`
--
ALTER TABLE `withdrawal_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `withdrawal_returns`
--
ALTER TABLE `withdrawal_returns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `withdrawal_return_items`
--
ALTER TABLE `withdrawal_return_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `permission_group_permissions`
--
ALTER TABLE `permission_group_permissions`
  ADD CONSTRAINT `fk_permission_group_permissions_group` FOREIGN KEY (`group_id`) REFERENCES `permission_groups` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_permission_group` FOREIGN KEY (`permission_group_id`) REFERENCES `permission_groups` (`id`);

--
-- Restrições para tabelas `withdrawal_orders`
--
ALTER TABLE `withdrawal_orders`
  ADD CONSTRAINT `fk_withdrawal_orders_cancelled_by` FOREIGN KEY (`cancelled_by_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_withdrawal_orders_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_withdrawal_orders_delivered_by` FOREIGN KEY (`delivered_by_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_withdrawal_orders_separated_by` FOREIGN KEY (`separated_by_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_withdrawal_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Restrições para tabelas `withdrawal_order_items`
--
ALTER TABLE `withdrawal_order_items`
  ADD CONSTRAINT `fk_withdrawal_items_order` FOREIGN KEY (`order_id`) REFERENCES `withdrawal_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_withdrawal_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Restrições para tabelas `withdrawal_payments`
--
ALTER TABLE `withdrawal_payments`
  ADD CONSTRAINT `fk_withdrawal_payments_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_withdrawal_payments_order` FOREIGN KEY (`order_id`) REFERENCES `withdrawal_orders` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `withdrawal_returns`
--
ALTER TABLE `withdrawal_returns`
  ADD CONSTRAINT `fk_withdrawal_returns_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_withdrawal_returns_order` FOREIGN KEY (`order_id`) REFERENCES `withdrawal_orders` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `withdrawal_return_items`
--
ALTER TABLE `withdrawal_return_items`
  ADD CONSTRAINT `fk_withdrawal_return_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `fk_withdrawal_return_items_return` FOREIGN KEY (`return_id`) REFERENCES `withdrawal_returns` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
