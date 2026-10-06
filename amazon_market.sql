-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 13-07-2026 a las 01:43:16
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `amazon_market`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categoria`
--

CREATE TABLE `categoria` (
  `id_categoria` int(11) NOT NULL,
  `nombre` varchar(25) NOT NULL,
  `descripcion` varchar(150) DEFAULT NULL,
  `estado` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `categoria`
--

INSERT INTO `categoria` (`id_categoria`, `nombre`, `descripcion`, `estado`) VALUES
(1, 'Abarrotes', NULL, 1),
(2, 'Bebidas', NULL, 1),
(3, 'Limpieza', NULL, 1),
(4, 'Lacteos', NULL, 1),
(5, 'Cuidado Personal', NULL, 1),
(6, 'Test Updated', NULL, 0);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cliente`
--

CREATE TABLE `cliente` (
  `id_cliente` int(11) NOT NULL,
  `id_tipo_documento` int(11) NOT NULL,
  `nro_documento` varchar(20) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `direccion` varchar(150) DEFAULT NULL,
  `telefono` varchar(15) NOT NULL,
  `email` varchar(50) DEFAULT NULL,
  `estado` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `cliente`
--

INSERT INTO `cliente` (`id_cliente`, `id_tipo_documento`, `nro_documento`, `nombre`, `direccion`, `telefono`, `email`, `estado`) VALUES
(1, 1, '46372736', 'Jose Dominguez', 'Calle Las Rosas', '95472643', 'josed@mail.com', 1),
(2, 1, '12345678', 'Juan Perez', 'Calle Las Rosas', '999999999', 'juan@mail.com', 1),
(3, 1, '41263745', 'Martin Sanchez', NULL, '999999999', NULL, 1),
(4, 1, '735673464', 'Prueba Validacion', 'Calle Las Rosas', '999999998', 'juan@mail.com', 0),
(5, 1, '66364812', 'Cliente Pruebas', 'Av. Las Rosas', '999999998', 'clienteprueba2@mail.com', 0),
(6, 2, '20512345678', 'Inversiones ABC S.A.C.', 'Av. Industrial 123, Callao', '999111222', 'abc@sac.com', 1),
(7, 2, '20600123456', 'Comercializadora Delta EIRL', 'Jr. Amazonas 456, Lima', '988222333', 'delta@eirl.com', 1),
(8, 2, '20456789012', 'Distribuidora Norte S.A.', 'Calle Los Olivos 789, San Martin de Porres', '977333444', 'norte@sa.com', 1),
(9, 2, '20345678900', 'Importaciones del Sur S.A.C.', 'Av. La Marina 321, San Miguel', '966444555', 'sur@sac.com', 1),
(10, 1, '45678912', 'Juan Perez Lopez', 'Av. Brasil 100, Jesus Maria', '955666777', NULL, 1),
(11, 1, '58293728', 'Maria Garcia Torres', 'Calle Unions 200, Bre??a', '944777888', NULL, 1),
(14, 1, '88888888', 'Test BOM', '', '999999999', '', 1),
(15, 1, '75374435', 'Jose Huaman', 'Calle Girasol 456', '', 'josehuaman@mail.com', 1),
(16, 1, '56654273', 'Pamela Alvarez', 'Calle Azul #123', '976263232', 'pamela@mail.com', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `detalle_venta`
--

CREATE TABLE `detalle_venta` (
  `id_detalle_venta` int(11) NOT NULL,
  `id_venta` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `cantidad` decimal(10,2) NOT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  `descuento` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `detalle_venta`
--

INSERT INTO `detalle_venta` (`id_detalle_venta`, `id_venta`, `id_producto`, `cantidad`, `precio_unitario`, `descuento`, `subtotal`) VALUES
(9, 8, 15, 5.35, 4.20, 0.00, 27.52),
(10, 8, 18, 6.70, 1.80, 0.00, 6.95),
(11, 8, 29, 7.19, 3.20, 0.00, 17.19),
(12, 9, 15, 4.28, 4.20, 0.00, 18.08),
(13, 9, 18, 7.68, 1.80, 0.00, 11.63),
(14, 9, 29, 8.29, 3.20, 0.00, 9.76),
(15, 10, 15, 7.38, 4.20, 0.00, 36.78),
(16, 10, 18, 2.64, 1.80, 0.00, 7.11),
(17, 10, 29, 1.82, 3.20, 0.00, 16.85),
(18, 11, 15, 1.86, 4.20, 0.00, 6.24),
(19, 11, 18, 9.86, 1.80, 0.00, 14.14),
(20, 11, 29, 8.69, 3.20, 0.00, 31.64),
(21, 12, 15, 4.36, 4.20, 0.00, 38.36),
(22, 12, 18, 4.59, 1.80, 0.00, 6.37),
(23, 12, 29, 2.93, 3.20, 0.00, 9.67),
(24, 13, 15, 5.34, 4.20, 0.00, 31.94),
(25, 13, 18, 3.02, 1.80, 0.00, 16.72),
(26, 13, 29, 9.39, 3.20, 0.00, 29.08),
(27, 14, 15, 7.25, 4.20, 0.00, 33.66),
(28, 14, 18, 8.31, 1.80, 0.00, 13.52),
(29, 14, 29, 2.63, 3.20, 0.00, 24.40),
(30, 15, 15, 2.23, 4.20, 0.00, 22.12),
(31, 15, 18, 9.65, 1.80, 0.00, 7.97),
(32, 15, 29, 1.20, 3.20, 0.00, 31.16),
(33, 16, 15, 8.07, 4.20, 0.00, 4.87),
(34, 16, 18, 7.58, 1.80, 0.00, 11.55),
(35, 16, 29, 8.34, 3.20, 0.00, 10.99),
(36, 17, 15, 9.16, 4.20, 0.00, 31.52),
(37, 17, 18, 9.04, 1.80, 0.00, 6.64),
(38, 17, 29, 8.32, 3.20, 0.00, 8.13),
(39, 18, 15, 4.74, 4.20, 0.00, 25.56),
(40, 18, 18, 6.20, 1.80, 0.00, 4.93),
(41, 18, 29, 3.09, 3.20, 0.00, 19.98),
(42, 19, 15, 2.95, 4.20, 0.00, 16.82),
(43, 19, 18, 1.18, 1.80, 0.00, 3.42),
(44, 19, 29, 4.95, 3.20, 0.00, 28.98),
(45, 20, 15, 2.43, 4.20, 0.00, 8.32),
(46, 20, 18, 1.62, 1.80, 0.00, 2.05),
(47, 20, 29, 8.84, 3.20, 0.00, 12.18),
(48, 21, 15, 9.50, 4.20, 0.00, 33.98),
(49, 21, 18, 1.95, 1.80, 0.00, 4.45),
(50, 21, 29, 5.51, 3.20, 0.00, 3.64),
(51, 22, 15, 6.15, 4.20, 0.00, 35.07),
(52, 22, 18, 4.30, 1.80, 0.00, 7.99),
(53, 22, 29, 8.29, 3.20, 0.00, 29.31),
(54, 23, 15, 1.91, 4.20, 0.00, 33.96),
(55, 23, 18, 6.69, 1.80, 0.00, 14.74),
(56, 23, 29, 1.89, 3.20, 0.00, 5.98),
(57, 24, 15, 2.67, 4.20, 0.00, 28.40),
(58, 24, 18, 6.79, 1.80, 0.00, 6.59),
(59, 24, 29, 5.94, 3.20, 0.00, 27.93),
(60, 25, 15, 6.81, 4.20, 0.00, 28.86),
(61, 25, 18, 3.93, 1.80, 0.00, 12.62),
(62, 25, 29, 4.28, 3.20, 0.00, 26.76),
(63, 26, 15, 9.98, 4.20, 0.00, 24.35),
(64, 26, 18, 7.05, 1.80, 0.00, 14.18),
(65, 26, 29, 8.22, 3.20, 0.00, 23.91),
(66, 27, 15, 2.70, 4.20, 0.00, 34.01),
(67, 27, 18, 4.37, 1.80, 0.00, 10.05),
(68, 27, 29, 4.79, 3.20, 0.00, 19.87),
(69, 28, 15, 6.68, 4.20, 0.00, 19.97),
(70, 28, 18, 2.74, 1.80, 0.00, 13.41),
(71, 28, 29, 1.03, 3.20, 0.00, 28.08),
(72, 29, 15, 3.80, 4.20, 0.00, 40.59),
(73, 29, 18, 8.93, 1.80, 0.00, 10.20),
(74, 29, 29, 9.54, 3.20, 0.00, 8.64),
(75, 30, 15, 1.88, 4.20, 0.00, 39.04),
(76, 30, 18, 3.84, 1.80, 0.00, 14.98),
(77, 30, 29, 2.08, 3.20, 0.00, 7.77),
(78, 31, 15, 4.90, 4.20, 0.00, 30.39),
(79, 31, 18, 2.47, 1.80, 0.00, 13.77),
(80, 31, 29, 2.83, 3.20, 0.00, 26.24),
(81, 32, 15, 4.51, 4.20, 0.00, 24.93),
(82, 32, 18, 6.16, 1.80, 0.00, 5.41),
(83, 32, 29, 4.54, 3.20, 0.00, 11.74),
(84, 33, 15, 3.73, 4.20, 0.00, 27.97),
(85, 33, 18, 3.10, 1.80, 0.00, 6.32),
(86, 33, 29, 7.25, 3.20, 0.00, 21.57),
(87, 34, 15, 1.94, 4.20, 0.00, 27.28),
(88, 34, 18, 7.65, 1.80, 0.00, 15.75),
(89, 34, 29, 1.81, 3.20, 0.00, 28.16),
(114, 43, 15, 4.40, 4.20, 0.00, 20.40),
(115, 43, 18, 1.08, 1.80, 0.00, 14.07),
(116, 43, 29, 7.85, 3.20, 0.00, 18.60),
(117, 44, 15, 4.51, 4.20, 0.00, 17.21),
(118, 44, 18, 5.96, 1.80, 0.00, 13.54),
(119, 44, 29, 9.73, 3.20, 0.00, 22.66),
(120, 45, 15, 5.22, 4.20, 0.00, 16.16),
(121, 45, 18, 2.58, 1.80, 0.00, 16.83),
(122, 45, 29, 2.01, 3.20, 0.00, 25.62),
(123, 46, 15, 6.00, 4.20, 0.00, 20.85),
(124, 46, 18, 5.84, 1.80, 0.00, 7.71),
(125, 46, 29, 2.90, 3.20, 0.00, 30.92),
(126, 47, 15, 2.61, 4.20, 0.00, 4.44),
(127, 47, 18, 5.46, 1.80, 0.00, 9.20),
(128, 47, 29, 8.19, 3.20, 0.00, 21.13),
(129, 48, 15, 7.46, 4.20, 0.00, 31.41),
(130, 48, 18, 5.01, 1.80, 0.00, 2.95),
(131, 48, 29, 1.14, 3.20, 0.00, 28.16),
(132, 49, 15, 3.57, 4.20, 0.00, 35.51),
(133, 49, 18, 3.57, 1.80, 0.00, 17.04),
(134, 49, 29, 8.64, 3.20, 0.00, 15.36),
(135, 50, 15, 6.07, 4.20, 0.00, 25.05),
(136, 50, 18, 1.60, 1.80, 0.00, 12.82),
(137, 50, 29, 2.80, 3.20, 0.00, 30.82),
(138, 51, 15, 2.76, 4.20, 0.00, 8.07),
(139, 51, 18, 9.31, 1.80, 0.00, 6.86),
(140, 51, 29, 8.11, 3.20, 0.00, 3.58),
(141, 52, 15, 7.26, 4.20, 0.00, 20.82),
(142, 52, 18, 2.00, 1.80, 0.00, 5.60),
(143, 52, 29, 8.55, 3.20, 0.00, 17.44),
(144, 53, 15, 9.58, 4.20, 0.00, 14.99),
(145, 53, 18, 6.10, 1.80, 0.00, 17.59),
(146, 53, 29, 2.56, 3.20, 0.00, 30.43),
(147, 54, 15, 2.86, 4.20, 0.00, 11.56),
(148, 54, 18, 4.19, 1.80, 0.00, 4.86),
(149, 54, 29, 8.91, 3.20, 0.00, 27.14),
(150, 55, 15, 5.67, 4.20, 0.00, 7.89),
(151, 55, 18, 9.40, 1.80, 0.00, 7.87),
(152, 55, 29, 1.66, 3.20, 0.00, 10.21),
(153, 56, 15, 9.97, 4.20, 0.00, 13.78),
(154, 56, 18, 3.49, 1.80, 0.00, 11.91),
(155, 56, 29, 3.61, 3.20, 0.00, 19.81),
(156, 57, 15, 1.13, 4.20, 0.00, 17.11),
(157, 57, 18, 6.98, 1.80, 0.00, 6.66),
(158, 57, 29, 5.54, 3.20, 0.00, 21.16),
(159, 58, 15, 6.43, 4.20, 0.00, 9.74),
(160, 58, 18, 9.30, 1.80, 0.00, 4.61),
(161, 58, 29, 1.89, 3.20, 0.00, 31.29),
(162, 59, 15, 6.21, 4.20, 0.00, 40.79),
(163, 59, 18, 1.94, 1.80, 0.00, 11.78),
(164, 59, 29, 7.92, 3.20, 0.00, 31.92),
(165, 60, 15, 7.10, 4.20, 0.00, 19.26),
(166, 60, 18, 9.63, 1.80, 0.00, 11.48),
(167, 60, 29, 2.00, 3.20, 0.00, 25.20),
(168, 61, 15, 5.37, 4.20, 0.00, 9.28),
(169, 61, 18, 2.95, 1.80, 0.00, 12.83),
(170, 61, 29, 7.78, 3.20, 0.00, 24.02),
(171, 62, 15, 4.20, 4.20, 0.00, 27.29),
(172, 62, 18, 9.88, 1.80, 0.00, 3.44),
(173, 62, 29, 5.92, 3.20, 0.00, 15.49),
(174, 63, 15, 5.45, 4.20, 0.00, 11.52),
(175, 63, 18, 5.36, 1.80, 0.00, 15.44),
(176, 63, 29, 7.79, 3.20, 0.00, 10.39),
(177, 64, 15, 9.85, 4.20, 0.00, 10.54),
(178, 64, 18, 9.00, 1.80, 0.00, 17.02),
(179, 64, 29, 1.28, 3.20, 0.00, 12.88),
(180, 65, 15, 6.30, 4.20, 0.00, 39.54),
(181, 65, 18, 9.18, 1.80, 0.00, 13.77),
(182, 65, 29, 9.71, 3.20, 0.00, 21.15),
(183, 66, 15, 2.91, 4.20, 0.00, 11.49),
(184, 66, 18, 3.94, 1.80, 0.00, 2.70),
(185, 66, 29, 3.68, 3.20, 0.00, 12.42),
(186, 67, 15, 7.37, 4.20, 0.00, 26.18),
(187, 67, 18, 8.04, 1.80, 0.00, 4.47),
(188, 67, 29, 5.32, 3.20, 0.00, 29.19),
(189, 68, 15, 1.67, 4.20, 0.00, 29.31),
(190, 68, 18, 1.89, 1.80, 0.00, 9.90),
(191, 68, 29, 2.83, 3.20, 0.00, 18.05),
(192, 69, 15, 9.72, 4.20, 0.00, 15.50),
(193, 69, 18, 6.29, 1.80, 0.00, 2.45),
(194, 69, 29, 4.94, 3.20, 0.00, 5.23),
(198, 71, 15, 7.08, 4.20, 0.00, 21.25),
(199, 71, 18, 3.06, 1.80, 0.00, 14.65),
(200, 71, 29, 3.50, 3.20, 0.00, 3.46),
(201, 72, 15, 2.91, 4.20, 0.00, 5.46),
(202, 72, 18, 5.77, 1.80, 0.00, 10.72),
(203, 72, 29, 2.47, 3.20, 0.00, 7.95),
(204, 73, 15, 4.00, 4.20, 0.00, 10.78),
(205, 73, 18, 8.83, 1.80, 0.00, 15.18),
(206, 73, 29, 5.68, 3.20, 0.00, 6.69),
(207, 74, 15, 1.42, 4.20, 0.00, 37.15),
(208, 74, 18, 2.95, 1.80, 0.00, 9.40),
(209, 74, 29, 7.25, 3.20, 0.00, 5.11),
(210, 75, 15, 3.23, 4.20, 0.00, 5.63),
(211, 75, 18, 5.03, 1.80, 0.00, 3.80),
(212, 75, 29, 3.48, 3.20, 0.00, 3.43),
(213, 76, 15, 2.92, 4.20, 0.00, 5.78),
(214, 76, 18, 6.12, 1.80, 0.00, 13.48),
(215, 76, 29, 9.07, 3.20, 0.00, 12.45),
(216, 77, 15, 9.24, 4.20, 0.00, 27.34),
(217, 77, 18, 3.84, 1.80, 0.00, 13.78),
(218, 77, 29, 7.77, 3.20, 0.00, 18.80),
(219, 78, 15, 5.07, 4.20, 0.00, 28.29),
(220, 78, 18, 8.46, 1.80, 0.00, 5.55),
(221, 78, 29, 7.05, 3.20, 0.00, 22.41),
(222, 79, 15, 3.86, 4.20, 0.00, 26.35),
(223, 79, 18, 9.80, 1.80, 0.00, 3.91),
(224, 79, 29, 7.46, 3.20, 0.00, 8.94),
(225, 80, 15, 8.58, 4.20, 0.00, 27.38),
(226, 80, 18, 5.86, 1.80, 0.00, 15.71),
(227, 80, 29, 7.07, 3.20, 0.00, 26.18),
(228, 81, 15, 9.68, 4.20, 0.00, 20.46),
(229, 81, 18, 3.31, 1.80, 0.00, 17.85),
(230, 81, 29, 2.66, 3.20, 0.00, 30.58),
(231, 82, 15, 2.80, 4.20, 0.00, 9.77),
(232, 82, 18, 2.24, 1.80, 0.00, 5.78),
(233, 82, 29, 8.33, 3.20, 0.00, 12.85),
(234, 83, 15, 3.10, 4.20, 0.00, 10.28),
(235, 83, 18, 1.94, 1.80, 0.00, 2.47),
(236, 83, 29, 9.03, 3.20, 0.00, 12.86),
(237, 84, 15, 1.01, 4.20, 0.00, 4.21),
(238, 84, 18, 9.98, 1.80, 0.00, 17.77),
(239, 84, 29, 9.43, 3.20, 0.00, 24.10),
(240, 85, 15, 8.37, 4.20, 0.00, 38.91),
(241, 85, 18, 2.20, 1.80, 0.00, 16.59),
(242, 85, 29, 2.48, 3.20, 0.00, 5.63),
(243, 86, 15, 9.35, 4.20, 0.00, 18.75),
(244, 86, 18, 2.28, 1.80, 0.00, 10.80),
(245, 86, 29, 4.17, 3.20, 0.00, 5.93),
(246, 87, 15, 4.76, 4.20, 0.00, 34.51),
(247, 87, 18, 7.82, 1.80, 0.00, 7.99),
(248, 87, 29, 6.73, 3.20, 0.00, 4.34),
(249, 88, 15, 3.58, 4.20, 0.00, 16.11),
(250, 88, 18, 7.43, 1.80, 0.00, 11.99),
(251, 88, 29, 1.01, 3.20, 0.00, 6.55),
(252, 89, 15, 6.22, 4.20, 0.00, 24.97),
(253, 89, 18, 1.07, 1.80, 0.00, 8.14),
(254, 89, 29, 9.40, 3.20, 0.00, 17.34),
(264, 93, 15, 6.33, 4.20, 0.00, 28.51),
(265, 93, 18, 4.96, 1.80, 0.00, 6.20),
(266, 93, 29, 1.33, 3.20, 0.00, 13.89),
(267, 94, 15, 7.71, 4.20, 0.00, 27.31),
(268, 94, 18, 8.39, 1.80, 0.00, 6.23),
(269, 94, 29, 9.13, 3.20, 0.00, 23.27),
(270, 95, 15, 7.97, 4.20, 0.00, 33.70),
(271, 95, 18, 6.21, 1.80, 0.00, 10.76),
(272, 95, 29, 1.25, 3.20, 0.00, 17.10),
(273, 96, 15, 3.95, 4.20, 0.00, 11.46),
(274, 96, 18, 9.79, 1.80, 0.00, 6.80),
(275, 96, 29, 6.50, 3.20, 0.00, 6.92),
(276, 97, 15, 8.33, 4.20, 0.00, 29.98),
(277, 97, 18, 9.71, 1.80, 0.00, 14.68),
(278, 97, 29, 1.64, 3.20, 0.00, 31.16),
(279, 98, 15, 6.76, 4.20, 0.00, 15.15),
(280, 98, 18, 5.75, 1.80, 0.00, 14.23),
(281, 98, 29, 3.29, 3.20, 0.00, 31.19),
(282, 99, 15, 1.85, 4.20, 0.00, 25.31),
(283, 99, 18, 5.57, 1.80, 0.00, 15.77),
(284, 99, 29, 8.10, 3.20, 0.00, 13.52),
(285, 100, 15, 4.82, 4.20, 0.00, 6.06),
(286, 100, 18, 9.75, 1.80, 0.00, 13.32),
(287, 100, 29, 6.77, 3.20, 0.00, 5.26),
(288, 101, 15, 4.91, 4.20, 0.00, 40.32),
(289, 101, 18, 5.28, 1.80, 0.00, 10.10),
(290, 101, 29, 2.21, 3.20, 0.00, 7.08),
(520, 9, 22, 5.31, 5.20, 0.00, 17.11),
(521, 9, 14, 4.53, 4.20, 0.00, 7.46),
(522, 12, 22, 4.29, 5.20, 0.00, 26.63),
(523, 12, 14, 1.74, 4.20, 0.00, 9.79),
(524, 15, 22, 5.44, 5.20, 0.00, 21.90),
(525, 15, 14, 3.74, 4.20, 0.00, 21.18),
(526, 18, 22, 3.00, 5.20, 0.00, 20.19),
(527, 18, 14, 4.41, 4.20, 0.00, 18.53),
(528, 21, 22, 2.83, 5.20, 0.00, 25.43),
(529, 21, 14, 4.97, 4.20, 0.00, 17.64),
(530, 24, 22, 5.08, 5.20, 0.00, 9.24),
(531, 24, 14, 2.66, 4.20, 0.00, 8.26),
(532, 27, 22, 5.85, 5.20, 0.00, 12.29),
(533, 27, 14, 3.26, 4.20, 0.00, 13.48),
(534, 29, 22, 5.27, 5.20, 0.00, 29.67),
(535, 29, 14, 1.73, 4.20, 0.00, 23.26),
(536, 30, 22, 1.49, 5.20, 0.00, 25.19),
(537, 30, 14, 3.75, 4.20, 0.00, 13.53),
(538, 33, 22, 3.86, 5.20, 0.00, 18.85),
(539, 33, 14, 5.55, 4.20, 0.00, 24.60),
(550, 45, 22, 4.03, 5.20, 0.00, 20.39),
(551, 45, 14, 1.51, 4.20, 0.00, 20.08),
(552, 48, 22, 3.38, 5.20, 0.00, 8.13),
(553, 48, 14, 1.67, 4.20, 0.00, 11.18),
(554, 51, 22, 2.30, 5.20, 0.00, 13.12),
(555, 51, 14, 4.71, 4.20, 0.00, 20.88),
(556, 53, 22, 4.73, 5.20, 0.00, 14.20),
(557, 53, 14, 3.47, 4.20, 0.00, 13.22),
(558, 54, 22, 4.34, 5.20, 0.00, 6.48),
(559, 54, 14, 2.22, 4.20, 0.00, 5.68),
(560, 57, 22, 4.10, 5.20, 0.00, 28.40),
(561, 57, 14, 4.00, 4.20, 0.00, 10.97),
(562, 60, 22, 5.06, 5.20, 0.00, 7.56),
(563, 60, 14, 1.10, 4.20, 0.00, 21.55),
(564, 62, 22, 1.36, 5.20, 0.00, 28.10),
(565, 62, 14, 1.94, 4.20, 0.00, 10.45),
(566, 63, 22, 5.62, 5.20, 0.00, 24.15),
(567, 63, 14, 5.36, 4.20, 0.00, 7.78),
(568, 65, 22, 2.19, 5.20, 0.00, 22.79),
(569, 65, 14, 4.35, 4.20, 0.00, 10.98),
(570, 66, 22, 4.01, 5.20, 0.00, 6.27),
(571, 66, 14, 3.00, 4.20, 0.00, 22.61),
(572, 69, 22, 1.92, 5.20, 0.00, 12.75),
(573, 69, 14, 5.50, 4.20, 0.00, 17.37),
(574, 72, 22, 3.18, 5.20, 0.00, 12.99),
(575, 72, 14, 1.95, 4.20, 0.00, 5.31),
(576, 74, 22, 4.46, 5.20, 0.00, 13.12),
(577, 74, 14, 3.23, 4.20, 0.00, 10.83),
(578, 75, 22, 2.20, 5.20, 0.00, 11.83),
(579, 75, 14, 3.77, 4.20, 0.00, 4.25),
(580, 78, 22, 2.76, 5.20, 0.00, 24.78),
(581, 78, 14, 4.55, 4.20, 0.00, 10.27),
(582, 80, 22, 2.58, 5.20, 0.00, 23.72),
(583, 80, 14, 4.07, 4.20, 0.00, 23.84),
(584, 81, 22, 5.16, 5.20, 0.00, 14.41),
(585, 81, 14, 2.38, 4.20, 0.00, 10.85),
(586, 83, 22, 4.78, 5.20, 0.00, 26.68),
(587, 83, 14, 5.33, 4.20, 0.00, 22.03),
(588, 84, 22, 4.25, 5.20, 0.00, 23.39),
(589, 84, 14, 3.75, 4.20, 0.00, 17.79),
(590, 87, 22, 3.94, 5.20, 0.00, 5.27),
(591, 87, 14, 2.23, 4.20, 0.00, 8.93),
(592, 88, 22, 2.93, 5.20, 0.00, 11.76),
(593, 88, 14, 1.52, 4.20, 0.00, 20.31),
(598, 94, 22, 3.10, 5.20, 0.00, 25.96),
(599, 94, 14, 4.66, 4.20, 0.00, 9.87),
(600, 96, 22, 1.76, 5.20, 0.00, 29.78),
(601, 96, 14, 2.37, 4.20, 0.00, 15.40),
(602, 97, 22, 5.22, 5.20, 0.00, 21.44),
(603, 97, 14, 3.95, 4.20, 0.00, 5.69),
(604, 100, 22, 3.94, 5.20, 0.00, 23.99),
(605, 100, 14, 5.27, 4.20, 0.00, 6.23),
(647, 18, 7, 1.76, 4.90, 0.00, 10.12),
(648, 18, 18, 1.05, 1.80, 0.00, 1.94),
(649, 27, 7, 1.22, 4.90, 0.00, 9.17),
(650, 27, 18, 1.70, 1.80, 0.00, 3.37),
(651, 30, 7, 3.28, 4.90, 0.00, 18.47),
(652, 30, 18, 2.01, 1.80, 0.00, 6.75),
(659, 51, 7, 3.88, 4.90, 0.00, 10.12),
(660, 51, 18, 3.68, 1.80, 0.00, 3.95),
(661, 54, 7, 1.94, 4.90, 0.00, 10.32),
(662, 54, 18, 3.72, 1.80, 0.00, 4.09),
(663, 60, 7, 2.20, 4.90, 0.00, 15.71),
(664, 60, 18, 2.41, 1.80, 0.00, 2.62),
(665, 63, 7, 2.04, 4.90, 0.00, 8.96),
(666, 63, 18, 2.02, 1.80, 0.00, 6.53),
(667, 66, 7, 2.08, 4.90, 0.00, 7.43),
(668, 66, 18, 3.34, 1.80, 0.00, 3.87),
(669, 75, 7, 2.74, 4.90, 0.00, 15.80),
(670, 75, 18, 3.91, 1.80, 0.00, 5.20),
(671, 78, 7, 1.71, 4.90, 0.00, 9.28),
(672, 78, 18, 3.33, 1.80, 0.00, 7.14),
(673, 81, 7, 2.85, 4.90, 0.00, 6.70),
(674, 81, 18, 3.27, 1.80, 0.00, 4.06),
(675, 84, 7, 3.47, 4.90, 0.00, 17.56),
(676, 84, 18, 3.51, 1.80, 0.00, 5.00),
(679, 97, 7, 1.19, 4.90, 0.00, 6.06),
(680, 97, 18, 1.60, 1.80, 0.00, 5.96),
(681, 100, 7, 1.74, 4.90, 0.00, 18.54),
(682, 100, 18, 3.69, 1.80, 0.00, 5.55),
(710, 108, 1, 3.00, 1.50, 0.00, 4.50),
(711, 109, 34, 3.00, 1.80, 0.00, 5.40),
(712, 110, 21, 1.00, 6.50, 0.00, 6.50),
(713, 110, 27, 2.00, 2.80, 0.00, 5.60),
(714, 111, 1, 2.00, 1.50, 0.00, 3.00),
(715, 111, 30, 1.00, 1.80, 0.00, 1.80),
(716, 111, 34, 1.00, 1.80, 0.00, 1.80),
(717, 112, 34, 1.00, 1.80, 0.00, 1.80),
(718, 112, 30, 1.00, 1.80, 0.00, 1.80),
(719, 112, 1, 1.00, 1.50, 0.00, 1.50),
(720, 113, 23, 1.00, 1.10, 0.00, 1.10),
(721, 113, 21, 1.00, 6.50, 0.00, 6.50),
(722, 113, 27, 1.00, 2.80, 0.00, 2.80),
(723, 114, 30, 2.00, 1.80, 0.00, 3.60),
(724, 114, 34, 1.00, 1.80, 0.00, 1.80),
(725, 114, 18, 1.00, 1.80, 0.00, 1.80),
(726, 115, 1, 1.00, 1.50, 0.00, 1.50),
(727, 115, 34, 1.00, 1.80, 0.00, 1.80),
(728, 116, 36, 3.00, 15.50, 0.00, 46.50),
(729, 117, 25, 1.00, 4.80, 0.00, 4.80),
(730, 118, 36, 1.00, 15.50, 0.00, 15.50),
(731, 119, 36, 2.00, 15.50, 0.00, 31.00),
(732, 120, 40, 1.00, 33.90, 0.00, 33.90);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `metodo_pago`
--

CREATE TABLE `metodo_pago` (
  `id_metodo_pago` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `estado` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `metodo_pago`
--

INSERT INTO `metodo_pago` (`id_metodo_pago`, `nombre`, `descripcion`, `estado`) VALUES
(1, 'Efectivo', NULL, 1),
(2, 'Tarjeta', NULL, 1),
(3, 'Yape / Plin', NULL, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `permiso`
--

CREATE TABLE `permiso` (
  `id_permiso` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `grupo` varchar(50) NOT NULL DEFAULT 'general',
  `estado` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `permiso`
--

INSERT INTO `permiso` (`id_permiso`, `nombre`, `slug`, `descripcion`, `grupo`, `estado`) VALUES
(1, 'Ver Dashboard', 'dashboard.ver', 'Acceder al panel principal de estadisticas', 'Dashboard', 1),
(2, 'Listar Productos', 'productos.listar', 'Ver el listado de productos', 'Productos', 1),
(3, 'Crear Productos', 'productos.crear', 'Registrar nuevos productos', 'Productos', 1),
(4, 'Editar Productos', 'productos.editar', 'Modificar informacion de productos', 'Productos', 1),
(5, 'Eliminar Productos', 'productos.eliminar', 'Dar de baja productos del inventario', 'Productos', 1),
(6, 'Listar Clientes', 'clientes.listar', 'Ver el directorio de clientes', 'Clientes', 1),
(7, 'Crear Clientes', 'clientes.crear', 'Registrar nuevos clientes', 'Clientes', 1),
(8, 'Editar Clientes', 'clientes.editar', 'Modificar informacion de clientes', 'Clientes', 1),
(9, 'Eliminar Clientes', 'clientes.eliminar', 'Dar de baja clientes', 'Clientes', 1),
(10, 'Listar Usuarios', 'usuarios.listar', 'Ver el directorio de usuarios', 'Usuarios', 1),
(11, 'Crear Usuarios', 'usuarios.crear', 'Registrar nuevos usuarios del sistema', 'Usuarios', 1),
(12, 'Editar Usuarios', 'usuarios.editar', 'Modificar informacion de usuarios', 'Usuarios', 1),
(13, 'Eliminar Usuarios', 'usuarios.eliminar', 'Dar de baja usuarios del sistema', 'Usuarios', 1),
(14, 'Acceder Punto de Venta', 'ventas.acceder', 'Abrir el terminal de facturacion', 'Ventas', 1),
(15, 'Procesar Ventas', 'ventas.procesar', 'Registrar y procesar ventas', 'Ventas', 1),
(16, 'Ver Reportes', 'reportes.ver', 'Acceder a reportes de ventas e ingresos', 'Reportes', 1),
(17, 'Acceder Turnos', 'turnos.acceder', 'Ver m??dulo de turnos', 'general', 1),
(18, 'Abrir Turno', 'turnos.abrir', 'Abrir turno de caja', 'general', 1),
(19, 'Cerrar Turno', 'turnos.cerrar', 'Cerrar turno y hacer cuadre de caja', 'general', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `producto`
--

CREATE TABLE `producto` (
  `id_producto` int(11) NOT NULL,
  `codigo_barra` varchar(50) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `id_categoria` int(11) NOT NULL,
  `id_unidad` int(11) NOT NULL,
  `precio_venta` decimal(10,2) NOT NULL,
  `stock_actual` decimal(10,2) NOT NULL,
  `stock_minimo` decimal(10,2) NOT NULL DEFAULT 1.00,
  `estado` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `producto`
--

INSERT INTO `producto` (`id_producto`, `codigo_barra`, `nombre`, `descripcion`, `id_categoria`, `id_unidad`, `precio_venta`, `stock_actual`, `stock_minimo`, `estado`) VALUES
(1, '7750182003049', 'Agua San Luis 500 ml', 'Agua sin gas 500 ml', 2, 1, 1.50, 71.00, 20.00, 1),
(2, '7750236173896', 'Inca Kola 500 ml Menos Calorias', 'Gaseosa Inca Kola 500 ml menos calorias', 2, 1, 2.80, 60.00, 15.00, 1),
(3, '7750182006088', 'Inka Kola 600 ml', 'Gaseosa Inka Cola 600 ml', 2, 1, 3.00, 50.00, 12.00, 1),
(4, '7750236000246', 'Inca Kola 2.25 L', 'Gaseosa Inca Kola 2.25 litros', 2, 1, 8.50, 30.00, 8.00, 1),
(5, '7750182001243', 'Inka Kola Retornable 3 L', 'Gaseosa Inka Kola retornable 3 litros', 2, 1, 11.50, 20.00, 5.00, 1),
(6, '7751271034081', 'Leche Evaporada Gloria 390 g', 'Leche evaporada Gloria lata 390 g', 4, 1, 4.80, 70.00, 15.00, 1),
(7, '7751271021975', 'Leche Evaporada Gloria 400 g', 'Leche evaporada Gloria lata 400 g', 4, 1, 4.90, 40.00, 10.00, 1),
(8, '7778606000061', 'Leche Evaporada Nestle 400 g', 'Leche evaporada Nestle lata 400 g', 4, 1, 4.80, 40.00, 10.00, 1),
(9, '8445290227027', 'Leche Condensada Nestle 393 g', 'Leche condensada Nestle 393 g', 4, 1, 6.80, 25.00, 6.00, 1),
(10, '8445290401182', 'Ideal Cremosita Nestle 390 g', 'Mezcla lactea Ideal Cremosita 390 g', 4, 1, 5.50, 25.00, 6.00, 1),
(11, '7751271276733', 'Yomost', 'Bebida lactea Yomost', 4, 1, 3.50, 35.00, 10.00, 1),
(12, '7751271017367', 'Yogurt Griego Gloria', 'Yogurt griego Gloria', 4, 1, 4.50, 25.00, 6.00, 1),
(13, '7751271037266', 'Pro Power Dark Chocolate Gloria', 'Bebida lactea Gloria Pro Power Dark Chocolate', 4, 1, 4.20, 18.00, 5.00, 1),
(14, '7751271037280', 'Pro Day Mora Gloria', 'Yogurt Gloria Pro Day sabor mora', 4, 1, 4.20, 18.00, 5.00, 1),
(15, '7751271037259', 'Pro Day Vainilla Gloria 320 ml', 'Bebida lactea Gloria Pro Day vainilla', 4, 1, 4.20, 18.00, 5.00, 1),
(16, '7751271032117', 'Gloria 320 ml', 'Bebida lactea Gloria 320 ml', 4, 1, 3.80, 20.00, 5.00, 1),
(17, '7751271011457', 'Gloria Tableta para Taza Chocolate 90 g', 'Tableta para taza sabor chocolate Gloria 90 g', 1, 1, 2.80, 30.00, 8.00, 1),
(18, '7751271025287', 'Batidito Gloria 80 g', 'Postre lacteo Batidito Gloria 80 g', 4, 1, 1.80, 29.00, 8.00, 1),
(19, '7613036450133', 'Cubo Carne Maggi 75.2 g', 'Mezcla deshidratada sabor carne', 1, 1, 2.50, 45.00, 12.00, 1),
(20, '7622201717544', 'Club Social Original 24 g', 'Galleta salada Club Social Original 24 g', 1, 1, 1.00, 60.00, 15.00, 1),
(21, '7622210854209', 'Club Social Multicereales 216 g', 'Galleta salada Club Social Multicereales 9 unidades', 1, 1, 6.50, 20.00, 6.00, 1),
(22, '7622201717759', 'Club Social x 6 Un 144 g', 'Galleta Club Social x 6 unidades 144 g', 1, 1, 5.20, 25.00, 6.00, 1),
(23, '7590011205158', 'Club Social Original 26 g', 'Galleta Club Social Original 26 g', 1, 1, 1.10, 49.00, 15.00, 1),
(24, '7750885001458', 'Frac Clasica Costa 132 g', 'Wafer Frac Clasica Costa 132 g', 1, 1, 2.20, 50.00, 12.00, 1),
(25, '7750885346665', 'Chocman Costa 180 g', 'Chocman Costa 180 g', 1, 1, 4.80, 23.00, 6.00, 1),
(26, '7750885021203', 'Frac Sabor Menta Costa 41 g', 'Galleta Frac sabor menta Costa 41 g', 1, 1, 1.80, 35.00, 10.00, 1),
(27, '7750885020879', 'Choco Donuts Crunch Costa', 'Galletas con cereal crocante con cobertura sabor chocolate', 1, 1, 2.80, 17.00, 6.00, 1),
(28, '7750885021333', 'Doblon Costa 12.5 g', 'Galleta Doblon Costa 12.5 g', 1, 1, 0.80, 80.00, 20.00, 1),
(29, '7750885015356', 'Agua Line Costa 210 g', 'Galleta Agua Line Costa 210 g', 1, 1, 3.20, 1.00, 6.00, 1),
(30, '7750885016421', 'Cereal Bar Costa', 'Barrita Cereal Bar Costa', 1, 1, 1.80, 24.00, 8.00, 1),
(31, '7750885019897', 'Wafer Chocolate Tira Nik Costa 72 g', 'Wafer chocolate Tira Nik Costa 72 g', 1, 1, 2.20, 30.00, 8.00, 1),
(32, '7750885008068', 'Costa 35 g', 'Producto Costa 35 g', 1, 1, 1.50, 30.00, 8.00, 1),
(33, '7750885024303', 'Galleta Costa Wenazas 50.4 g', 'Galleta Costa Wenazas 50.4 g', 1, 1, 2.00, 26.00, 8.00, 1),
(34, '7750243048873', 'Casino Black Victoria 43.5 g', 'Galleta Casino Black Victoria 43.5 g', 1, 1, 1.80, 23.00, 8.00, 1),
(35, '7750670012218', 'Bebida Energizante VOLT Ginseng Botella 300ml', '', 2, 1, 2.50, 30.00, 5.00, 1),
(36, '7750243059381', 'Aceite Vegetal PRIMOR Clásico Botella 1.8L', '', 1, 1, 15.50, 44.00, 5.00, 1),
(37, '7751158010443', 'Trozos de Atún FLORIDA en Aceite Vegetal Lata 140g', '', 1, 1, 5.80, 50.00, 5.00, 1),
(38, '2000000000001', 'Fideos Cabello de Ángel MOLITALIA Bolsa 250g', '', 1, 1, 1.70, 50.00, 5.00, 1),
(39, '7750885176125', 'Fideos Canuto MOLITALIA Bolsa 250g', '', 1, 1, 1.70, 50.00, 5.00, 1),
(40, '2000000000002', 'Aceite de Oliva EL OLIVAR Extra Virgen Botella 500ml', '', 1, 1, 33.90, 47.00, 5.00, 1),
(41, '2000000000003', 'Gaseosa INCA KOLA sin Azúcar Botella 1L', '', 1, 1, 5.00, 50.00, 5.00, 1),
(42, '7750243058766', 'Fideos DON VITTORIO Rigatoni Bolsa 500g', '', 1, 1, 3.50, 50.00, 5.00, 1),
(43, '2000000000004', 'Pasta Dental COLGATE Total Anti-sarro Prevención Activa Caja 3x75ml', '', 1, 4, 4.99, 50.00, 5.00, 1),
(44, '7501001164645', 'Shampoo PANTENE Rizos Definidos Frasco 400ml', '', 1, 1, 20.90, 50.00, 5.00, 1),
(45, '7750243051194', 'Fideos Corbata NICOLINI Bolsa 250g', '', 1, 1, 1.50, 50.00, 5.00, 1),
(46, '2200201985511', 'Azúcar Blanca BELL\'S Bolsa 1Kg', '', 1, 1, 4.60, 50.00, 5.00, 1),
(47, '2200201985528', 'Azúcar Rubia BELL\'S Bolsa 1Kg', '', 1, 1, 3.80, 50.00, 5.00, 1),
(48, '7750727005965', 'Galletas de Maíz SALMAS Horneadas Caja 216g', '', 1, 1, 11.50, 50.00, 5.00, 1),
(49, '7754725781616', 'Arroz Extra Añejo VALLENORTE Gran Reserva Bolsa 5Kg', '', 1, 1, 22.90, 50.00, 5.00, 1),
(50, '2000000000005', 'Arroz Extra BELL\'S Bolsa 750g', '', 1, 1, 3.50, 50.00, 5.00, 1),
(51, '7755139161759', 'Arroz Extra COSTEÑO Bolsa 750g', '', 1, 1, 4.50, 50.00, 5.00, 1),
(52, '2200201985481', 'Azúcar Rubia BELL\'S Bolsa 2Kg', '', 1, 1, 6.90, 50.00, 5.00, 1),
(53, '7755139246890', 'Arroz Extra COSTEÑO Bolsa 5Kg', '', 1, 1, 23.90, 50.00, 5.00, 1),
(54, '2000000000006', 'Azúcar Rubia CARTAVIO Bolsa 5Kg', '', 1, 1, 14.90, 50.00, 5.00, 1),
(55, '7794640170720', 'Pasta Dental SENSODYNE Blanqueador Repara y Protege Tubo 100g', '', 1, 1, 24.90, 50.00, 5.00, 1),
(56, '2000000000007', 'Gaseosa COCA COLA sin Azúcar Botella 500ml', '', 2, 1, 4.99, 50.00, 5.00, 1),
(57, '7750182155663', 'Gaseosa COCA COLA Sabor Original Botella 1.5L', '', 2, 1, 7.30, 50.00, 5.00, 1),
(58, '2000000000008', 'Gaseosa INCA KOLA Sabor Original Botella 3L', '', 2, 1, 12.20, 50.00, 5.00, 1),
(59, '7750182003919', 'Gaseosa COCA COLA sin Azúcar Botella 2.25L', '', 2, 1, 8.50, 50.00, 5.00, 1),
(60, '2000000000009', 'Gaseosa INCA KOLA Botella 2.25L', '', 2, 1, 9.90, 50.00, 5.00, 1),
(61, '7750182000796', 'Gaseosa COCA COLA Botella 2.25L', '', 2, 1, 9.90, 50.00, 5.00, 1),
(62, '2200202018935', 'Aguaymanto Pelado BELL\'S Bandeja 200g', '', 1, 1, 4.19, 50.00, 5.00, 1),
(63, '2000000000010', 'Bebida Rehidratante SPORADE Tropical Electrolitos Botella 1.5L', '', 2, 1, 5.50, 50.00, 5.00, 1),
(64, '2000000000011', 'Bebida Rehidratante SPORADE Mandarina Botella 500ml', '', 2, 1, 2.20, 50.00, 5.00, 1),
(65, '7750182002684', 'Ginger Ale SCHWEPPES Citrus Botella 1.5L', '', 2, 1, 5.90, 50.00, 5.00, 1),
(66, '7751737000698', 'Jugo de Fruta L\'ONDA Cranberry Botella 500ml', '', 2, 1, 5.50, 50.00, 5.00, 1),
(67, '8712000025649', 'Cerveza HEINEKEN Barril 5 L', '', 2, 1, 84.90, 50.00, 5.00, 1),
(68, '7750057000241', 'Lija al Agua 3M Grano 150 Negro Impermeable', '', 2, 1, 2.40, 50.00, 5.00, 1),
(69, '7753749002059', 'Agua Mineral SAN MATEO Sin Gas Bidón 7L', '', 2, 1, 8.30, 50.00, 5.00, 1),
(70, '2000000000012', 'Jugo de Fruta KERO Naranja y Piña Botella 475ml', '', 2, 1, 4.50, 50.00, 5.00, 1),
(71, '7750670015035', 'Bebida Natural BIO Manzana Aguaje y Cocona Botella 300ml', '', 2, 1, 3.90, 50.00, 5.00, 1),
(72, '2000000000013', 'Bebida Energizante MONSTER Energy Lata 473ml', '', 2, 1, 7.90, 50.00, 5.00, 1),
(73, '2200202018461', 'Mix Blueberries Aguaymanto BELL\'S Bandeja 300g', '', 2, 1, 13.49, 50.00, 5.00, 1),
(74, '2000000000014', 'Gaseosa CRUSH Naranja Botella 3L', '', 2, 1, 5.90, 50.00, 5.00, 1),
(75, '2000000000015', 'Gaseosa KR Naranja Botella 3.03L', '', 2, 1, 4.99, 50.00, 5.00, 1),
(76, '7751851025096', 'Lavavajilla Líquido SAPOLIO Manzana Frasco 1250ml', '', 3, 1, 12.50, 50.00, 5.00, 1),
(77, '2000000000016', 'Limpiador Líquido Multiuso SAPOLIO Lavanda Botella 1.8L', '', 3, 1, 6.40, 50.00, 5.00, 1),
(78, '2000000000017', 'Limpiador para Inodoros BRIXIL Botella 1L', '', 3, 1, 4.99, 50.00, 5.00, 1),
(79, '2000000000018', 'Lavavajilla Líquida Limón Cif 900 ml', '', 3, 1, 13.20, 50.00, 5.00, 1),
(80, '7752285036542', 'Limpiador Cif Pisos Flor de Algodón 3.5 Lt', '', 3, 1, 17.80, 50.00, 5.00, 1),
(81, '7752285036559', 'Limpiador Cif Pisos Lavanda 3.5 Lt', '', 3, 1, 17.80, 50.00, 5.00, 1),
(82, '7756641005760', 'Limpiador POETT Frescura de Lavanda Botella 900ml', '', 3, 1, 3.90, 50.00, 5.00, 1),
(83, '2000000000019', 'Limpiador Multiusos BOREAL Brisas Botella 1800ml', '', 3, 1, 4.99, 50.00, 5.00, 1),
(84, '7754326002141', 'Limpiador Multiusos BOREAL Floral Botella 1800ml', '', 3, 1, 4.90, 50.00, 5.00, 1),
(85, '2000000000020', 'Yogurt Bebible GLORIA sin Lactosa Fresa Botella 1Kg', '', 4, 1, 4.99, 50.00, 5.00, 1),
(86, '2000000000021', 'Yogurt Griego LAIVE Frutos Rojos Botella 800g', '', 4, 1, 9.90, 50.00, 5.00, 1),
(87, '2000000000022', 'Leche Chocolatada UHT GLORIA Caja 946ml', '', 4, 1, 6.19, 50.00, 5.00, 1),
(88, '125666', 'Queso Edam LAIVE', '', 4, 2, 49.90, 50.00, 5.00, 1),
(89, '2000000000023', 'Yogurt GLORIA Batishake Fresa Vaso 120g', '', 4, 1, 1.69, 50.00, 5.00, 1),
(90, '2000000000024', 'Yogurt Griego LAIVE con Trozos de Blueberry Vaso 120g', '', 4, 1, 2.40, 50.00, 5.00, 1),
(91, '7755073001807', 'Yogurt Griego TIGO de Arándano & Chía Pote 1Kg', '', 4, 1, 16.60, 50.00, 5.00, 1),
(92, '7751271030786', 'Bebida de Soya UHT SOY VIDA Caja 1L', '', 4, 1, 6.50, 50.00, 5.00, 1),
(93, '2000000000025', 'Yogurt Griego TIGO Frutos Rojos Pote 1Kg', '', 4, 1, 4.99, 50.00, 5.00, 1),
(94, '7751271029766', 'Bebida de Leche UHT BONLÉ Chocolatada Bolsa 800ml', '', 4, 1, 2.90, 50.00, 5.00, 1),
(95, '2000000000026', 'Yogurt Griego VAKIMU Frutos del Bosque Pote 960g', '', 4, 1, 15.70, 50.00, 5.00, 1),
(96, '7751271029780', 'Queso Fresco BONLÉ x kg', '', 4, 2, 31.90, 50.00, 5.00, 1),
(97, '7750632005234', 'Yogurt Frutado DANLAC con Fresa Botella 900g', '', 4, 1, 9.90, 50.00, 5.00, 1),
(98, '7755073001753', 'Yogurt Griego TIGO Arándano con Chía Pote 160g', '', 4, 1, 5.50, 50.00, 5.00, 1),
(99, '2000000000027', 'Queso Gouda BONLÉ', '', 4, 1, 4.99, 50.00, 5.00, 1),
(100, '2000000000028', 'Yogurt Frutado DANLAC de Fresa Frasco 160g', '', 4, 1, 4.90, 50.00, 5.00, 1),
(101, '2000000000029', 'Yogurt Griego DANLAC Cultivos Probióticos Frasco 420g', '', 4, 1, 12.90, 50.00, 5.00, 1),
(102, '7802950002577', 'Crema de Leche NESTLÉ Lata 300g', '', 4, 1, 10.50, 50.00, 5.00, 1),
(103, '7754014001463', 'Shampoo de alfombras Daryza 1gl', '', 5, 1, 26.20, 50.00, 5.00, 1),
(104, '7896015520045', 'Pasta Dental SENSODYNE Blanqueador Extra Fresh - Tubo 90g', '', 5, 1, 21.90, 50.00, 5.00, 1),
(105, '7702031291565', 'Shampoo para Bebé JOHNSON\'S BABY Original Frasco 200ml', '', 5, 1, 21.00, 50.00, 5.00, 1),
(106, '7750670010238', 'Rehidratante SPORADE Tropical Botella 500ml', '', 2, 1, 2.50, 20.00, 5.00, 1),
(107, '7750182000321', 'Gaseosa SPRITE Botella 500ml', '', 2, 1, 2.50, 25.00, 5.00, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `rol`
--

CREATE TABLE `rol` (
  `id_rol` int(11) NOT NULL,
  `nombre` varchar(15) NOT NULL,
  `descripcion` varchar(150) DEFAULT NULL,
  `estado` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `rol`
--

INSERT INTO `rol` (`id_rol`, `nombre`, `descripcion`, `estado`) VALUES
(1, 'Administrador', 'Acceso total al sistema. Gestion de usuarios, productos, clientes, ventas y reportes.', 1),
(2, 'Vendedor', 'Acceso a ventas, clientes y productos. Solo lectura de inventario.', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `rol_permiso`
--

CREATE TABLE `rol_permiso` (
  `id_rol` int(11) NOT NULL,
  `id_permiso` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Volcado de datos para la tabla `rol_permiso`
--

INSERT INTO `rol_permiso` (`id_rol`, `id_permiso`) VALUES
(1, 1),
(1, 2),
(1, 3),
(1, 4),
(1, 5),
(1, 6),
(1, 7),
(1, 8),
(1, 9),
(1, 10),
(1, 11),
(1, 12),
(1, 13),
(1, 14),
(1, 15),
(1, 16),
(2, 1),
(2, 2),
(2, 6),
(2, 7),
(2, 8),
(2, 14),
(2, 15),
(2, 17),
(2, 18),
(2, 19);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `serie_comprobante`
--

CREATE TABLE `serie_comprobante` (
  `id_serie` int(11) NOT NULL,
  `id_tipo_comprobante` int(11) NOT NULL,
  `serie` varchar(10) NOT NULL,
  `correlativo_actual` int(11) NOT NULL DEFAULT 0,
  `estado` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `serie_comprobante`
--

INSERT INTO `serie_comprobante` (`id_serie`, `id_tipo_comprobante`, `serie`, `correlativo_actual`, `estado`) VALUES
(1, 1, 'B001', 65, 1),
(2, 2, 'F001', 30, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tipo_comprobante`
--

CREATE TABLE `tipo_comprobante` (
  `id_tipo_comprobante` int(11) NOT NULL,
  `nombre` varchar(25) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `estado` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `tipo_comprobante`
--

INSERT INTO `tipo_comprobante` (`id_tipo_comprobante`, `nombre`, `descripcion`, `estado`) VALUES
(1, 'Boleta', NULL, 1),
(2, 'Factura', NULL, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tipo_documento`
--

CREATE TABLE `tipo_documento` (
  `id_tipo_documento` int(11) NOT NULL,
  `nombre` varchar(25) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `estado` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `tipo_documento`
--

INSERT INTO `tipo_documento` (`id_tipo_documento`, `nombre`, `descripcion`, `estado`) VALUES
(1, 'DNI', NULL, 1),
(2, 'RUC', NULL, 1),
(3, 'Pasaporte', NULL, 1),
(4, 'Carne de Extranjeria', NULL, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `unidad_medida`
--

CREATE TABLE `unidad_medida` (
  `id_unidad` int(11) NOT NULL,
  `nombre` varchar(10) NOT NULL,
  `abreviatura` char(4) NOT NULL,
  `estado` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `unidad_medida`
--

INSERT INTO `unidad_medida` (`id_unidad`, `nombre`, `abreviatura`, `estado`) VALUES
(1, 'Unidad', 'UND', 1),
(2, 'Kilogramo', 'KG', 1),
(3, 'Litro', 'LTR', 1),
(4, 'Paquete', 'PQT', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuario`
--

CREATE TABLE `usuario` (
  `id_usuario` int(11) NOT NULL,
  `id_rol` int(11) NOT NULL,
  `username` varchar(10) NOT NULL,
  `password_hash` text NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `dni` varchar(8) NOT NULL,
  `telefono` varchar(9) NOT NULL,
  `direccion` varchar(100) NOT NULL,
  `email` varchar(50) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `estado` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuario`
--

INSERT INTO `usuario` (`id_usuario`, `id_rol`, `username`, `password_hash`, `nombre`, `dni`, `telefono`, `direccion`, `email`, `fecha_registro`, `estado`) VALUES
(1, 1, 'admin', '$2y$10$8ACcHCjjO1IMBUD4lEdZe.EXd7GJ7Z6ucM4dM8YAfga0Q3I2gXnr.', 'Admin', '11111111', '999999999', 'Jr. Miraflores Cdra. 12', NULL, '2026-04-16 23:19:43', 1),
(4, 2, 'alex', '$2y$10$7x7QCqFmBPuvA8zcAGi4neja9la0Cpbai0UFojZ4ZzrQCXx0ljhSG', 'Alex Garcia', '12345678', '999999999', 'Calle Las Rosas', 'alex@mail.com', '2026-04-26 19:31:38', 1),
(6, 2, 'maria', '$2y$10$8ACcHCjjO1IMBUD4lEdZe.EXd7GJ7Z6ucM4dM8YAfga0Q3I2gXnr.', 'Maria Lopez', '45678901', '911222333', 'Av. Los Olivos 456', 'maria@mail.com', '2026-07-09 18:28:36', 1),
(7, 2, 'carlos', '$2y$10$8ACcHCjjO1IMBUD4lEdZe.EXd7GJ7Z6ucM4dM8YAfga0Q3I2gXnr.', 'Carlos Ramirez', '78901234', '922333444', 'Jr. San Martin 789', 'carlos@mail.com', '2026-07-09 18:28:36', 1),
(8, 2, 'ana', '$2y$10$8ACcHCjjO1IMBUD4lEdZe.EXd7GJ7Z6ucM4dM8YAfga0Q3I2gXnr.', 'Ana Torres', '34567890', '933444555', 'Calle Comercio 321', 'ana@mail.com', '2026-07-09 18:28:36', 1),
(9, 1, 'Superuser', '$2y$10$sEtWXqzDcNjnhW0kqllpie1OpEmh09N6lV8jrNcGs16IHwlSt6XfW', 'Superuser', '23231232', '912321321', 'Av Aviacion #12323', 'supersuer@mail.com', '2026-07-12 17:16:30', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `venta`
--

CREATE TABLE `venta` (
  `id_venta` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `id_tipo_comprobante` int(11) NOT NULL,
  `serie` varchar(10) NOT NULL,
  `numero` varchar(20) NOT NULL,
  `fecha_venta` datetime NOT NULL DEFAULT current_timestamp(),
  `total` decimal(10,2) NOT NULL,
  `id_metodo_pago` int(11) NOT NULL,
  `estado` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `venta`
--

INSERT INTO `venta` (`id_venta`, `id_cliente`, `id_usuario`, `id_tipo_comprobante`, `serie`, `numero`, `fecha_venta`, `total`, `id_metodo_pago`, `estado`) VALUES
(8, 1, 7, 1, 'B001', '00000001', '2026-01-05 09:15:00', 51.66, 1, 1),
(9, 3, 6, 2, 'F001', '00000001', '2026-01-12 14:30:00', 64.04, 1, 1),
(10, 5, 6, 1, 'B001', '00000006', '2026-01-18 10:45:00', 60.74, 1, 1),
(11, 2, 4, 1, 'B001', '00000007', '2026-01-25 16:20:00', 52.02, 2, 1),
(12, 7, 4, 2, 'F001', '00000004', '2026-02-02 11:00:00', 90.82, 1, 1),
(13, 4, 6, 1, 'B001', '00000011', '2026-02-08 13:15:00', 77.74, 1, 1),
(14, 9, 4, 1, 'B001', '00000013', '2026-02-15 09:30:00', 71.58, 3, 1),
(15, 6, 7, 2, 'F001', '00000008', '2026-02-22 15:45:00', 104.33, 1, 1),
(16, 11, 8, 1, 'B001', '00000017', '2026-03-01 10:00:00', 27.41, 1, 1),
(17, 8, 4, 1, 'B001', '00000019', '2026-03-08 14:30:00', 46.29, 2, 1),
(18, 1, 8, 2, 'F001', '00000010', '2026-03-15 11:15:00', 101.25, 1, 1),
(19, 10, 7, 1, 'B001', '00000024', '2026-03-22 16:00:00', 49.22, 1, 1),
(20, 3, 6, 1, 'B001', '00000025', '2026-03-29 09:45:00', 22.55, 3, 1),
(21, 1, 4, 2, 'F001', '00000013', '2026-04-05 13:30:00', 85.14, 1, 1),
(22, 5, 7, 1, 'B001', '00000030', '2026-04-12 10:15:00', 72.37, 1, 1),
(23, 7, 8, 1, 'B001', '00000031', '2026-04-19 15:00:00', 54.68, 2, 1),
(24, 2, 4, 2, 'F001', '00000017', '2026-04-26 11:30:00', 80.42, 1, 1),
(25, 9, 6, 1, 'B001', '00000036', '2026-05-03 14:45:00', 68.24, 1, 1),
(26, 4, 8, 1, 'B001', '00000037', '2026-05-10 09:00:00', 62.44, 3, 1),
(27, 6, 7, 2, 'F001', '00000019', '2026-05-17 16:15:00', 102.24, 1, 1),
(28, 11, 4, 1, 'B001', '00000042', '2026-05-24 10:30:00', 61.46, 1, 1),
(29, 8, 8, 1, 'B001', '00000044', '2026-05-31 13:45:00', 112.36, 2, 1),
(30, 1, 7, 2, 'F001', '00000023', '2026-06-07 11:00:00', 125.73, 1, 1),
(31, 10, 8, 1, 'B001', '00000048', '2026-06-14 15:30:00', 70.40, 1, 1),
(32, 3, 6, 1, 'B001', '00000049', '2026-06-21 09:15:00', 42.08, 3, 1),
(33, 1, 6, 2, 'F001', '00000026', '2026-06-28 14:00:00', 99.31, 1, 1),
(34, 5, 7, 1, 'B001', '00000054', '2026-07-05 10:45:00', 71.19, 1, 1),
(43, 2, 6, 1, 'B001', '00000002', '2026-01-08 10:00:00', 53.07, 1, 1),
(44, 5, 6, 1, 'B001', '00000005', '2026-01-15 14:30:00', 53.41, 1, 1),
(45, 8, 8, 2, 'F001', '00000003', '2026-01-22 11:15:00', 99.08, 1, 1),
(46, 1, 4, 1, 'B001', '00000008', '2026-01-29 16:00:00', 59.48, 3, 1),
(47, 10, 7, 1, 'B001', '00000010', '2026-02-05 09:45:00', 34.77, 1, 1),
(48, 3, 4, 2, 'F001', '00000006', '2026-02-12 13:30:00', 81.83, 1, 1),
(49, 7, 7, 1, 'B001', '00000014', '2026-02-19 10:15:00', 67.91, 1, 1),
(50, 1, 6, 1, 'B001', '00000016', '2026-02-26 15:00:00', 68.69, 2, 1),
(51, 4, 4, 2, 'F001', '00000009', '2026-03-05 11:30:00', 66.58, 1, 1),
(52, 9, 7, 1, 'B001', '00000021', '2026-03-12 14:45:00', 43.86, 1, 1),
(53, 6, 8, 1, 'B001', '00000023', '2026-03-19 09:00:00', 90.43, 3, 1),
(54, 11, 6, 2, 'F001', '00000012', '2026-03-26 16:15:00', 70.13, 1, 1),
(55, 2, 8, 1, 'B001', '00000027', '2026-04-02 10:30:00', 25.97, 1, 1),
(56, 5, 8, 1, 'B001', '00000028', '2026-04-09 13:45:00', 45.50, 1, 1),
(57, 8, 4, 2, 'F001', '00000015', '2026-04-16 11:00:00', 84.30, 1, 1),
(58, 1, 7, 1, 'B001', '00000032', '2026-04-23 15:30:00', 45.64, 3, 1),
(59, 10, 8, 1, 'B001', '00000035', '2026-04-30 09:15:00', 84.49, 1, 1),
(60, 3, 8, 2, 'F001', '00000018', '2026-05-07 14:00:00', 103.38, 1, 1),
(61, 7, 8, 1, 'B001', '00000039', '2026-05-14 10:45:00', 46.13, 1, 1),
(62, 1, 7, 1, 'B001', '00000041', '2026-05-21 16:30:00', 84.77, 2, 1),
(63, 4, 8, 2, 'F001', '00000021', '2026-05-28 11:15:00', 84.77, 1, 1),
(64, 9, 6, 1, 'B001', '00000045', '2026-06-04 13:00:00', 40.44, 1, 1),
(65, 6, 4, 1, 'B001', '00000047', '2026-06-11 09:30:00', 108.23, 3, 1),
(66, 11, 4, 2, 'F001', '00000024', '2026-06-18 15:45:00', 66.79, 1, 1),
(67, 2, 6, 1, 'B001', '00000051', '2026-06-25 10:00:00', 59.84, 1, 1),
(68, 5, 7, 1, 'B001', '00000053', '2026-07-02 14:15:00', 57.26, 1, 1),
(69, 8, 8, 2, 'F001', '00000027', '2026-07-09 11:30:00', 53.30, 1, 1),
(71, 4, 4, 1, 'B001', '00000003', '2026-01-10 11:00:00', 39.36, 1, 1),
(72, 7, 6, 2, 'F001', '00000002', '2026-01-20 15:30:00', 42.43, 1, 1),
(73, 1, 4, 1, 'B001', '00000009', '2026-01-30 09:45:00', 32.65, 1, 1),
(74, 10, 8, 1, 'B001', '00000012', '2026-02-09 14:00:00', 75.61, 2, 1),
(75, 3, 7, 2, 'F001', '00000007', '2026-02-19 10:15:00', 49.94, 1, 1),
(76, 6, 7, 1, 'B001', '00000018', '2026-03-01 16:30:00', 31.71, 1, 1),
(77, 9, 6, 1, 'B001', '00000020', '2026-03-11 11:45:00', 59.92, 3, 1),
(78, 1, 4, 2, 'F001', '00000011', '2026-03-21 09:00:00', 107.72, 1, 1),
(79, 2, 6, 1, 'B001', '00000026', '2026-03-31 15:15:00', 39.20, 1, 1),
(80, 5, 8, 1, 'B001', '00000029', '2026-04-10 10:30:00', 116.83, 2, 1),
(81, 8, 8, 2, 'F001', '00000016', '2026-04-20 14:45:00', 104.91, 1, 1),
(82, 11, 4, 1, 'B001', '00000034', '2026-04-30 09:00:00', 28.40, 1, 1),
(83, 4, 8, 1, 'B001', '00000038', '2026-05-10 16:00:00', 74.32, 3, 1),
(84, 7, 7, 2, 'F001', '00000020', '2026-05-20 11:30:00', 109.82, 1, 1),
(85, 1, 8, 1, 'B001', '00000043', '2026-05-30 15:45:00', 61.13, 1, 1),
(86, 10, 6, 1, 'B001', '00000046', '2026-06-09 10:15:00', 35.48, 1, 1),
(87, 3, 8, 2, 'F001', '00000025', '2026-06-19 14:30:00', 61.04, 1, 1),
(88, 6, 4, 1, 'B001', '00000052', '2026-06-29 09:00:00', 66.72, 2, 1),
(89, 9, 7, 1, 'B001', '00000055', '2026-07-09 16:15:00', 50.45, 1, 1),
(93, 8, 4, 1, 'B001', '00000004', '2026-01-14 10:30:00', 48.60, 1, 1),
(94, 3, 6, 2, 'F001', '00000005', '2026-02-04 14:00:00', 92.64, 1, 1),
(95, 11, 8, 1, 'B001', '00000015', '2026-02-24 09:15:00', 61.56, 1, 1),
(96, 6, 7, 1, 'B001', '00000022', '2026-03-16 16:45:00', 70.36, 2, 1),
(97, 1, 6, 2, 'F001', '00000014', '2026-04-06 11:30:00', 114.97, 1, 1),
(98, 9, 4, 1, 'B001', '00000033', '2026-04-26 15:00:00', 60.57, 1, 1),
(99, 4, 8, 1, 'B001', '00000040', '2026-05-16 10:15:00', 54.60, 3, 1),
(100, 7, 6, 2, 'F001', '00000022', '2026-06-05 14:30:00', 78.95, 1, 1),
(101, 1, 4, 1, 'B001', '00000050', '2026-06-25 09:45:00', 57.50, 1, 1),
(108, 11, 7, 1, 'B001', '00000056', '2026-07-09 18:52:40', 4.50, 1, 1),
(109, 8, 7, 1, 'B001', '00000057', '2026-07-09 18:57:35', 5.40, 1, 1),
(110, 11, 8, 1, 'B001', '00000058', '2026-07-09 18:59:29', 12.10, 1, 1),
(111, 9, 4, 2, 'F001', '00000028', '2026-07-09 19:01:18', 6.60, 1, 1),
(112, 7, 6, 1, 'B001', '00000059', '2026-07-09 19:04:21', 5.10, 1, 1),
(113, 9, 6, 1, 'B001', '00000060', '2026-07-09 19:06:08', 10.40, 1, 1),
(114, 10, 7, 1, 'B001', '00000061', '2026-07-09 19:06:52', 7.20, 1, 1),
(115, 8, 8, 1, 'B001', '00000062', '2026-07-09 19:07:52', 3.30, 1, 1),
(116, 8, 1, 2, 'F001', '00000029', '2026-07-09 21:43:11', 46.50, 1, 1),
(117, 8, 4, 1, 'B001', '00000063', '2026-07-11 14:59:57', 4.80, 1, 1),
(118, 3, 1, 1, 'B001', '00000064', '2026-07-12 18:01:00', 15.50, 1, 1),
(119, 3, 1, 1, 'B001', '00000065', '2026-07-12 18:04:45', 31.00, 1, 1),
(120, 6, 1, 2, 'F001', '00000030', '2026-07-12 18:26:20', 33.90, 1, 1);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `categoria`
--
ALTER TABLE `categoria`
  ADD PRIMARY KEY (`id_categoria`);

--
-- Indices de la tabla `cliente`
--
ALTER TABLE `cliente`
  ADD PRIMARY KEY (`id_cliente`),
  ADD UNIQUE KEY `uq_cliente_doc` (`id_tipo_documento`,`nro_documento`),
  ADD KEY `FK_CLIENTE_TIPODOCUMENTO` (`id_tipo_documento`);

--
-- Indices de la tabla `detalle_venta`
--
ALTER TABLE `detalle_venta`
  ADD PRIMARY KEY (`id_detalle_venta`),
  ADD KEY `FK_DETALLEVENTA_VENTA` (`id_venta`),
  ADD KEY `FK_DETALLEVENTA_PRODUCTO` (`id_producto`);

--
-- Indices de la tabla `metodo_pago`
--
ALTER TABLE `metodo_pago`
  ADD PRIMARY KEY (`id_metodo_pago`);

--
-- Indices de la tabla `permiso`
--
ALTER TABLE `permiso`
  ADD PRIMARY KEY (`id_permiso`),
  ADD UNIQUE KEY `uk_slug` (`slug`);

--
-- Indices de la tabla `producto`
--
ALTER TABLE `producto`
  ADD PRIMARY KEY (`id_producto`),
  ADD UNIQUE KEY `uq_codigo_barra` (`codigo_barra`),
  ADD KEY `FK_PRODUCTO_CATEGORIA` (`id_categoria`),
  ADD KEY `FK_PRODUCTO_UNIDADMEDIDA` (`id_unidad`);

--
-- Indices de la tabla `rol`
--
ALTER TABLE `rol`
  ADD PRIMARY KEY (`id_rol`);

--
-- Indices de la tabla `rol_permiso`
--
ALTER TABLE `rol_permiso`
  ADD PRIMARY KEY (`id_rol`,`id_permiso`),
  ADD KEY `fk_rp_permiso` (`id_permiso`);

--
-- Indices de la tabla `serie_comprobante`
--
ALTER TABLE `serie_comprobante`
  ADD PRIMARY KEY (`id_serie`),
  ADD UNIQUE KEY `uq_tipo_comprobante` (`id_tipo_comprobante`),
  ADD UNIQUE KEY `uq_tipo_serie` (`id_tipo_comprobante`,`serie`);

--
-- Indices de la tabla `tipo_comprobante`
--
ALTER TABLE `tipo_comprobante`
  ADD PRIMARY KEY (`id_tipo_comprobante`);

--
-- Indices de la tabla `tipo_documento`
--
ALTER TABLE `tipo_documento`
  ADD PRIMARY KEY (`id_tipo_documento`);

--
-- Indices de la tabla `unidad_medida`
--
ALTER TABLE `unidad_medida`
  ADD PRIMARY KEY (`id_unidad`);

--
-- Indices de la tabla `usuario`
--
ALTER TABLE `usuario`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `uq_username` (`username`),
  ADD KEY `FK_USUARIO_ROL` (`id_rol`);

--
-- Indices de la tabla `venta`
--
ALTER TABLE `venta`
  ADD PRIMARY KEY (`id_venta`),
  ADD UNIQUE KEY `uq_comprobante` (`id_tipo_comprobante`,`serie`,`numero`),
  ADD KEY `FK_VENTA_CLIENTE` (`id_cliente`),
  ADD KEY `FK_VENTA_USUARIO` (`id_usuario`),
  ADD KEY `FK_VENTA_TIPOCOMPROBANTE` (`id_tipo_comprobante`),
  ADD KEY `FK_VENTA_METODOPAGO` (`id_metodo_pago`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `categoria`
--
ALTER TABLE `categoria`
  MODIFY `id_categoria` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `cliente`
--
ALTER TABLE `cliente`
  MODIFY `id_cliente` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT de la tabla `detalle_venta`
--
ALTER TABLE `detalle_venta`
  MODIFY `id_detalle_venta` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=733;

--
-- AUTO_INCREMENT de la tabla `metodo_pago`
--
ALTER TABLE `metodo_pago`
  MODIFY `id_metodo_pago` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `permiso`
--
ALTER TABLE `permiso`
  MODIFY `id_permiso` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT de la tabla `producto`
--
ALTER TABLE `producto`
  MODIFY `id_producto` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=108;

--
-- AUTO_INCREMENT de la tabla `rol`
--
ALTER TABLE `rol`
  MODIFY `id_rol` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `serie_comprobante`
--
ALTER TABLE `serie_comprobante`
  MODIFY `id_serie` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `tipo_comprobante`
--
ALTER TABLE `tipo_comprobante`
  MODIFY `id_tipo_comprobante` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `tipo_documento`
--
ALTER TABLE `tipo_documento`
  MODIFY `id_tipo_documento` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `unidad_medida`
--
ALTER TABLE `unidad_medida`
  MODIFY `id_unidad` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `usuario`
--
ALTER TABLE `usuario`
  MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de la tabla `venta`
--
ALTER TABLE `venta`
  MODIFY `id_venta` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=121;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `cliente`
--
ALTER TABLE `cliente`
  ADD CONSTRAINT `FK_CLIENTE_TIPODOCUMENTO` FOREIGN KEY (`id_tipo_documento`) REFERENCES `tipo_documento` (`id_tipo_documento`);

--
-- Filtros para la tabla `detalle_venta`
--
ALTER TABLE `detalle_venta`
  ADD CONSTRAINT `FK_DETALLEVENTA_PRODUCTO` FOREIGN KEY (`id_producto`) REFERENCES `producto` (`id_producto`),
  ADD CONSTRAINT `FK_DETALLEVENTA_VENTA` FOREIGN KEY (`id_venta`) REFERENCES `venta` (`id_venta`);

--
-- Filtros para la tabla `producto`
--
ALTER TABLE `producto`
  ADD CONSTRAINT `FK_PRODUCTO_CATEGORIA` FOREIGN KEY (`id_categoria`) REFERENCES `categoria` (`id_categoria`),
  ADD CONSTRAINT `FK_PRODUCTO_UNIDADMEDIDA` FOREIGN KEY (`id_unidad`) REFERENCES `unidad_medida` (`id_unidad`);

--
-- Filtros para la tabla `rol_permiso`
--
ALTER TABLE `rol_permiso`
  ADD CONSTRAINT `fk_rp_permiso` FOREIGN KEY (`id_permiso`) REFERENCES `permiso` (`id_permiso`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rp_rol` FOREIGN KEY (`id_rol`) REFERENCES `rol` (`id_rol`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `serie_comprobante`
--
ALTER TABLE `serie_comprobante`
  ADD CONSTRAINT `FK_SERIE_TIPOCOMPROBANTE` FOREIGN KEY (`id_tipo_comprobante`) REFERENCES `tipo_comprobante` (`id_tipo_comprobante`);

--
-- Filtros para la tabla `usuario`
--
ALTER TABLE `usuario`
  ADD CONSTRAINT `FK_USUARIO_ROL` FOREIGN KEY (`id_rol`) REFERENCES `rol` (`id_rol`);

--
-- Filtros para la tabla `venta`
--
ALTER TABLE `venta`
  ADD CONSTRAINT `FK_VENTA_CLIENTE` FOREIGN KEY (`id_cliente`) REFERENCES `cliente` (`id_cliente`),
  ADD CONSTRAINT `FK_VENTA_METODOPAGO` FOREIGN KEY (`id_metodo_pago`) REFERENCES `metodo_pago` (`id_metodo_pago`),
  ADD CONSTRAINT `FK_VENTA_TIPOCOMPROBANTE` FOREIGN KEY (`id_tipo_comprobante`) REFERENCES `tipo_comprobante` (`id_tipo_comprobante`),
  ADD CONSTRAINT `FK_VENTA_USUARIO` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
