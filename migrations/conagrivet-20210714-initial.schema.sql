-- MySQL dump 10.13  Distrib 8.0.25, for macos10.15 (x86_64)
--
-- Host: localhost    Database: conagrivet
-- ------------------------------------------------------
-- Server version	8.0.25

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `consultant`
--

DROP TABLE IF EXISTS `consultant`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consultant` (
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `job_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `auth_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `roles` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '(DC2Type:simple_array)',
  PRIMARY KEY (`name`),
  UNIQUE KEY `UNIQ_441282A15933D02C` (`auth_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contract`
--

DROP TABLE IF EXISTS `contract`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contract` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `recipient_id` int unsigned NOT NULL,
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_E98F2859E92F8F78` (`recipient_id`),
  CONSTRAINT `FK_E98F2859E92F8F78` FOREIGN KEY (`recipient_id`) REFERENCES `recipient` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=388 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contracted_service`
--

DROP TABLE IF EXISTS `contracted_service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contracted_service` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `contract_id` int unsigned NOT NULL,
  `service_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `consultant_id` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contract_unique` (`contract_id`,`service_id`,`consultant_id`),
  KEY `IDX_45372AA72576E0FD` (`contract_id`),
  KEY `IDX_45372AA7ED5CA9E6` (`service_id`),
  KEY `IDX_45372AA744F779A2` (`consultant_id`),
  CONSTRAINT `FK_45372AA72576E0FD` FOREIGN KEY (`contract_id`) REFERENCES `contract` (`id`),
  CONSTRAINT `FK_45372AA744F779A2` FOREIGN KEY (`consultant_id`) REFERENCES `consultant` (`name`),
  CONSTRAINT `FK_45372AA7ED5CA9E6` FOREIGN KEY (`service_id`) REFERENCES `service` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=852 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary view structure for view `contracted_service_extd`
--

DROP TABLE IF EXISTS `contracted_service_extd`;
/*!50001 DROP VIEW IF EXISTS `contracted_service_extd`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `contracted_service_extd` AS SELECT 
 1 AS `id`,
 1 AS `recipient_id`,
 1 AS `recipient_name`,
 1 AS `service`,
 1 AS `consultant`,
 1 AS `hours`,
 1 AS `hours_on_premises`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `doctrine_migration_versions`
--

DROP TABLE IF EXISTS `doctrine_migration_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `doctrine_migration_versions` (
  `version` varchar(191) COLLATE utf8_unicode_ci NOT NULL,
  `executed_at` datetime DEFAULT NULL,
  `execution_time` int DEFAULT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recipient`
--

DROP TABLE IF EXISTS `recipient`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipient` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `vat_id` varchar(13) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fiscal_code` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `headquarters` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_6804FB495E237E06` (`name`),
  UNIQUE KEY `UNIQ_6804FB49B5B63A6B` (`vat_id`),
  UNIQUE KEY `UNIQ_6804FB49D7BBA58B` (`fiscal_code`)
) ENGINE=InnoDB AUTO_INCREMENT=389 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `schedule`
--

DROP TABLE IF EXISTS `schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schedule` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `consultant_id` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `uuid` binary(16) NOT NULL COMMENT '(DC2Type:uuid)',
  `from` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `to` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `IDX_5A3811FB44F779A2` (`consultant_id`),
  CONSTRAINT `FK_5A3811FB44F779A2` FOREIGN KEY (`consultant_id`) REFERENCES `consultant` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `schedule_changeset`
--

DROP TABLE IF EXISTS `schedule_changeset`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schedule_changeset` (
  `id` binary(16) NOT NULL COMMENT '(DC2Type:ulid)',
  `schedule_id` int unsigned NOT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `IDX_6E6F1568A40BC2D5` (`schedule_id`),
  CONSTRAINT `FK_6E6F1568A40BC2D5` FOREIGN KEY (`schedule_id`) REFERENCES `schedule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `schedule_command`
--

DROP TABLE IF EXISTS `schedule_command`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schedule_command` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `changeset_id` binary(16) NOT NULL COMMENT '(DC2Type:ulid)',
  `task_id` int unsigned NOT NULL,
  `order` int unsigned NOT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `start` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `end` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `previous_start` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `previous_end` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `IDX_3A0C665C6135CC9` (`changeset_id`),
  KEY `IDX_3A0C6658DB60186` (`task_id`),
  CONSTRAINT `FK_3A0C6658DB60186` FOREIGN KEY (`task_id`) REFERENCES `task` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_3A0C665C6135CC9` FOREIGN KEY (`changeset_id`) REFERENCES `schedule_changeset` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service`
--

DROP TABLE IF EXISTS `service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service` (
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `hours` smallint unsigned NOT NULL,
  `hours_on_premises` smallint unsigned NOT NULL,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `category` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `steps` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '(DC2Type:array)',
  `reasons` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '(DC2Type:array)',
  `expectations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `task_preferred_on_premises_hours` smallint unsigned DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `task`
--

DROP TABLE IF EXISTS `task`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` int unsigned NOT NULL,
  `contracted_service_id` int unsigned NOT NULL,
  `start` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `end` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `on_premises` tinyint(1) NOT NULL,
  `state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '(DC2Type:array)',
  `consultant_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_527EDB25A40BC2D5` (`schedule_id`),
  KEY `IDX_527EDB256DA0E7B7` (`contracted_service_id`),
  CONSTRAINT `FK_527EDB256DA0E7B7` FOREIGN KEY (`contracted_service_id`) REFERENCES `contracted_service` (`id`),
  CONSTRAINT `FK_527EDB25A40BC2D5` FOREIGN KEY (`schedule_id`) REFERENCES `schedule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7838 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary view structure for view `task_extd`
--

DROP TABLE IF EXISTS `task_extd`;
/*!50001 DROP VIEW IF EXISTS `task_extd`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `task_extd` AS SELECT 
 1 AS `id`,
 1 AS `deleted`,
 1 AS `schedule_id`,
 1 AS `contracted_service_id`,
 1 AS `on_premises`,
 1 AS `start`,
 1 AS `end`,
 1 AS `hours`,
 1 AS `consultant`,
 1 AS `recipient`,
 1 AS `service`*/;
SET character_set_client = @saved_cs_client;

--
-- Final view structure for view `contracted_service_extd`
--

/*!50001 DROP VIEW IF EXISTS `contracted_service_extd`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `contracted_service_extd` AS select `cs`.`id` AS `id`,`r`.`id` AS `recipient_id`,`r`.`name` AS `recipient_name`,`cs`.`service_id` AS `service`,`cs`.`consultant_id` AS `consultant`,`s`.`hours` AS `hours`,`s`.`hours_on_premises` AS `hours_on_premises` from (((`contracted_service` `cs` left join `contract` `c` on((`cs`.`contract_id` = `c`.`id`))) left join `recipient` `r` on((`c`.`recipient_id` = `r`.`id`))) left join `service` `s` on((`cs`.`service_id` = `s`.`name`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `task_extd`
--

/*!50001 DROP VIEW IF EXISTS `task_extd`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `task_extd` AS select `t`.`id` AS `id`,if((`t`.`deleted_at` is null),0,1) AS `deleted`,`t`.`schedule_id` AS `schedule_id`,`t`.`contracted_service_id` AS `contracted_service_id`,`t`.`on_premises` AS `on_premises`,`t`.`start` AS `start`,`t`.`end` AS `end`,timestampdiff(HOUR,`t`.`start`,`t`.`end`) AS `hours`,`cs`.`consultant_id` AS `consultant`,`r`.`name` AS `recipient`,`cs`.`service_id` AS `service` from ((((`task` `t` left join `contracted_service` `cs` on((`t`.`contracted_service_id` = `cs`.`id`))) left join `contract` `c` on((`cs`.`contract_id` = `c`.`id`))) left join `recipient` `r` on((`c`.`recipient_id` = `r`.`id`))) left join `service` `s` on((`cs`.`service_id` = `s`.`name`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2021-07-14 16:51:39
