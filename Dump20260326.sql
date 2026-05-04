-- MySQL dump 10.13  Distrib 8.0.42, for Win64 (x86_64)
--
-- Host: localhost    Database: parke
-- ------------------------------------------------------
-- Server version	8.0.42

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `location_id` int NOT NULL,
  `slot_id` int DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `details` text,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `location_id` (`location_id`),
  KEY `slot_id` (`slot_id`),
  KEY `action` (`action`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (1,1,1,'no_show_cancelled','No-show booking cancelled for vehicle UGA 346R (booked for 02:15 PM)','2026-03-23 14:36:21'),(2,1,1,'manual_checkin','Manual check-in for vehicle: UGA 346R','2026-03-23 14:41:43'),(3,1,1,'no_show','No-show booking for vehicle UGA 346R - Slot made available','2026-03-23 14:42:28'),(4,1,1,'no_show','No-show booking for vehicle UGA 346R - Slot made available','2026-03-23 15:19:10');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bookings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `slot_id` int NOT NULL,
  `location_id` int NOT NULL,
  `vehicle_number` varchar(20) NOT NULL,
  `start_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `end_time` timestamp NULL DEFAULT NULL,
  `total_cost` decimal(10,2) DEFAULT '0.00',
  `payment_status` enum('pending','paid','cancelled') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `slot_id` (`slot_id`),
  KEY `location_id` (`location_id`),
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`slot_id`) REFERENCES `parking_slots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`location_id`) REFERENCES `parking_locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bookings`
--

LOCK TABLES `bookings` WRITE;
/*!40000 ALTER TABLE `bookings` DISABLE KEYS */;
INSERT INTO `bookings` VALUES (7,2,1,1,'UGA 346R','2026-03-11 09:31:00','2026-03-11 10:31:00',1000.00,'paid','2026-03-11 09:31:04'),(8,2,1,1,'UGA 346R','2026-03-18 20:07:00','2026-03-18 23:07:00',3000.00,'cancelled','2026-03-18 08:06:46'),(9,2,1,1,'UGA 346R','2026-03-18 08:53:00','2026-03-18 09:53:00',1000.00,'paid','2026-03-18 08:50:34'),(10,2,1,1,'UGA 346R','2026-03-23 11:15:00','2026-03-23 11:36:19',1000.00,'cancelled','2026-03-23 11:14:12'),(11,2,1,1,'UGA 346R','2026-03-23 11:41:42','2026-03-23 11:42:27',1000.00,'cancelled','2026-03-23 11:37:07'),(12,2,1,1,'UGA 346R','2026-03-23 12:16:00','2026-03-23 12:19:09',1000.00,'cancelled','2026-03-23 12:14:34');
/*!40000 ALTER TABLE `bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `favorites`
--

DROP TABLE IF EXISTS `favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `favorites` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `location_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_favorite` (`user_id`,`location_id`),
  KEY `location_id` (`location_id`),
  CONSTRAINT `favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `favorites_ibfk_2` FOREIGN KEY (`location_id`) REFERENCES `parking_locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `favorites`
--

LOCK TABLES `favorites` WRITE;
/*!40000 ALTER TABLE `favorites` DISABLE KEYS */;
/*!40000 ALTER TABLE `favorites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parking_locations`
--

DROP TABLE IF EXISTS `parking_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parking_locations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `address` varchar(255) NOT NULL,
  `city` varchar(100) NOT NULL,
  `state` varchar(100) DEFAULT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `total_slots` int NOT NULL,
  `available_slots` int DEFAULT '0',
  `security_info` text,
  `amenities` text,
  `operating_hours` varchar(100) DEFAULT '24/7',
  `contact_phone` varchar(20) DEFAULT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `price_per_hour` decimal(10,2) DEFAULT '0.00',
  `image_path` varchar(255) DEFAULT NULL,
  `gallery_images` text,
  `location_username` varchar(50) DEFAULT NULL,
  `location_password` varchar(255) DEFAULT NULL,
  `location_manager_name` varchar(100) DEFAULT NULL,
  `location_manager_phone` varchar(20) DEFAULT NULL,
  `location_manager_email` varchar(100) DEFAULT NULL,
  `has_camera_access` tinyint(1) DEFAULT '0',
  `has_gate_access` tinyint(1) DEFAULT '0',
  `admin_id` int DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `location_username` (`location_username`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `parking_locations_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parking_locations`
--

LOCK TABLES `parking_locations` WRITE;
/*!40000 ALTER TABLE `parking_locations` DISABLE KEYS */;
INSERT INTO `parking_locations` VALUES (1,'A&G parking','Bunga ,Ggaba Road','Kampala','Uganda',0.34760000,32.58250000,20,20,'','','24/7','0785673045','a&gparking@gmail.com',1000.00,'uploads/locations/location_1771587797_699848d51dd35.jpg',NULL,'agparking','$2y$10$h6xQpKLNgHSF4g4uBkLMY.Y7FgcQ8Y0PDLL6YEX.fAlei3AqoORmu','Brain Muganga','0785673045','brainmuganga@gmail.com',1,1,1,'active','2026-02-20 11:43:17');
/*!40000 ALTER TABLE `parking_locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parking_slots`
--

DROP TABLE IF EXISTS `parking_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parking_slots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `location_id` int NOT NULL,
  `slot_number` varchar(10) NOT NULL,
  `slot_type` enum('standard','disabled','electric') DEFAULT 'standard',
  `status` enum('available','occupied','maintenance') DEFAULT 'available',
  `current_vehicle` varchar(20) DEFAULT NULL,
  `reserved_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `maintenance_reason` text,
  `last_updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_slot` (`location_id`,`slot_number`),
  CONSTRAINT `parking_slots_ibfk_1` FOREIGN KEY (`location_id`) REFERENCES `parking_locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parking_slots`
--

LOCK TABLES `parking_slots` WRITE;
/*!40000 ALTER TABLE `parking_slots` DISABLE KEYS */;
INSERT INTO `parking_slots` VALUES (1,1,'A001','standard','available',NULL,NULL,'2026-02-20 11:43:17',NULL,'2026-03-23 15:19:10'),(2,1,'A002','standard','available',NULL,NULL,'2026-02-20 11:43:17',NULL,NULL),(3,1,'A003','standard','available',NULL,NULL,'2026-02-20 11:43:17',NULL,NULL),(4,1,'A004','standard','available',NULL,NULL,'2026-02-20 11:43:17',NULL,NULL),(5,1,'A005','standard','available',NULL,NULL,'2026-02-20 11:43:17',NULL,NULL),(6,1,'A006','standard','available',NULL,NULL,'2026-02-20 11:43:17',NULL,NULL),(7,1,'A007','standard','available',NULL,NULL,'2026-02-20 11:43:18',NULL,NULL),(8,1,'A008','standard','available',NULL,NULL,'2026-02-20 11:43:18',NULL,NULL),(9,1,'A009','standard','available',NULL,NULL,'2026-02-20 11:43:18',NULL,NULL),(10,1,'A010','standard','available',NULL,NULL,'2026-02-20 11:43:18',NULL,NULL),(11,1,'A011','standard','available',NULL,NULL,'2026-02-20 11:43:18',NULL,NULL),(12,1,'A012','standard','available',NULL,NULL,'2026-02-20 11:43:18',NULL,NULL),(13,1,'A013','standard','available',NULL,NULL,'2026-02-20 11:43:18',NULL,NULL),(14,1,'A014','standard','available',NULL,NULL,'2026-02-20 11:43:18',NULL,NULL),(15,1,'A015','standard','available',NULL,NULL,'2026-02-20 11:43:18',NULL,NULL),(16,1,'A016','standard','available',NULL,NULL,'2026-02-20 11:43:18',NULL,NULL),(17,1,'A017','standard','available',NULL,NULL,'2026-02-20 11:43:19',NULL,NULL),(18,1,'A018','standard','available',NULL,NULL,'2026-02-20 11:43:19',NULL,NULL),(19,1,'A019','standard','available',NULL,NULL,'2026-02-20 11:43:19',NULL,NULL),(20,1,'A020','standard','available',NULL,NULL,'2026-02-20 11:43:19',NULL,NULL);
/*!40000 ALTER TABLE `parking_slots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `user_type` enum('driver','admin') DEFAULT 'driver',
  `phone` varchar(20) DEFAULT NULL,
  `picture` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `status` enum('active','inactive') DEFAULT 'active',
  `full_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','admin@parking.com','password','admin',NULL,NULL,NULL,'2026-02-20 10:50:57','2026-02-20 10:50:57','active',NULL),(2,'Nshuti Gasana Mark','nshutigasana@gmail.com','$2y$10$lrrWKtthGtdLl.0hBbl/HOfofoF60vm0GiruAZfc0Co4eOklR8zWq','driver','0786744321',NULL,'','2026-02-20 11:35:18','2026-02-24 07:43:53','active',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-26 12:52:50
