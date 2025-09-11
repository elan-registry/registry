-- MySQL dump 10.13  Distrib 5.7.39, for osx11.0 (x86_64)
--
-- Host: localhost    Database: elanregi_spice
-- ------------------------------------------------------
-- Server version	5.7.39

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `plg_charts_colors`
--

DROP TABLE IF EXISTS `plg_charts_colors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_charts_colors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `color` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plg_charts_colors`
--

LOCK TABLES `plg_charts_colors` WRITE;
/*!40000 ALTER TABLE `plg_charts_colors` DISABLE KEYS */;
INSERT INTO `plg_charts_colors` VALUES (1,'#FFFF99'),(2,'#991AFF'),(3,'#E6FF80'),(4,'#4D8066'),(5,'#4DB3FF'),(6,'#809980'),(7,'#66994D'),(8,'#E6B333'),(9,'#66991A'),(10,'#9900B3'),(11,'#CCCC00'),(12,'#00E680'),(13,'#CC9999'),(14,'#B34D4D'),(15,'#66E64D'),(16,'#33FFCC'),(17,'#1AFF33'),(18,'#CC80CC'),(19,'#FF3380'),(20,'#1AB399'),(21,'#66664D'),(22,'#E666B3'),(23,'#809900'),(24,'#FFB399'),(25,'#E64D66'),(26,'#FF4D4D'),(27,'#6680B3'),(28,'#FF6633'),(29,'#999933'),(30,'#999966'),(31,'#4D8000'),(32,'#B33300'),(33,'#4DB380'),(34,'#4D80CC'),(35,'#3366E6'),(36,'#B3B31A'),(37,'#E666FF'),(38,'#33991A'),(39,'#00B3E6'),(40,'#FF99E6'),(41,'#B366CC'),(42,'#CCFF1A'),(43,'#FF1A66'),(44,'#E6331A'),(45,'#6666FF'),(46,'#99FF99'),(47,'#99E6E6'),(48,'#FF33FF'),(49,'#E6B3B3'),(50,'#80B300');
/*!40000 ALTER TABLE `plg_charts_colors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plg_mysql_exp`
--

DROP TABLE IF EXISTS `plg_mysql_exp`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_mysql_exp` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `qname` varchar(255) NOT NULL,
  `query` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plg_mysql_exp`
--

LOCK TABLES `plg_mysql_exp` WRITE;
/*!40000 ALTER TABLE `plg_mysql_exp` DISABLE KEYS */;
/*!40000 ALTER TABLE `plg_mysql_exp` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plg_sl_logs`
--

DROP TABLE IF EXISTS `plg_sl_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_sl_logs` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `page` varchar(255) NOT NULL,
  `get_data` text,
  `post_data` text,
  `ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ip` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plg_sl_logs`
--

LOCK TABLES `plg_sl_logs` WRITE;
/*!40000 ALTER TABLE `plg_sl_logs` DISABLE KEYS */;
INSERT INTO `plg_sl_logs` VALUES (1,1,'admin.php','{\"view\":\"plugins\",\"err\":\"superlogger activated\"}','[]','2020-10-22 17:06:16','::1'),(2,1,'admin.php','{\"view\":\"plugins_config\",\"plugin\":\"superlogger\"}','[]','2020-10-22 17:06:19','::1'),(3,1,'index.php','[]','[]','2020-10-22 17:06:45','::1'),(4,1,'list_cars.php','[]','[]','2020-10-22 17:06:47','::1'),(5,1,'statistics.php','[]','[]','2020-10-22 17:06:50','::1'),(6,1,'index.php','[]','[]','2020-10-22 17:06:54','::1'),(7,1,'admin.php','[]','[]','2020-10-22 17:06:58','::1'),(8,1,'admin.php','{\"view\":\"plugins_config\",\"plugin\":\"superlogger\"}','[]','2020-10-22 17:07:03','::1'),(9,1,'admin.php','{\"view\":\"plugins_config\",\"plugin\":\"superlogger\",\"pages\":\"index.php\",\"submitPage\":\"Go\"}','[]','2020-10-22 17:07:12','::1'),(10,1,'admin.php','{\"view\":\"plugins\"}','[]','2020-10-22 17:07:21','::1'),(11,1,'admin.php','{\"view\":\"plugins\"}','{\"jump\":\"#ctrl-Super Logger Plugin\",\"plugin\":\"superlogger\",\"disable\":\"Disable\"}','2020-10-22 17:07:23','::1');
/*!40000 ALTER TABLE `plg_sl_logs` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-09-11  5:40:07
