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
-- Table structure for table `plg_cms_categories`
--

DROP TABLE IF EXISTS `plg_cms_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_cms_categories` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `perms` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `subcat_of` int(11) DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plg_cms_categories`
--

LOCK TABLES `plg_cms_categories` WRITE;
/*!40000 ALTER TABLE `plg_cms_categories` DISABLE KEYS */;
INSERT INTO `plg_cms_categories` VALUES (1,'Default','2',0),(2,'Stories','0',1);
/*!40000 ALTER TABLE `plg_cms_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plg_cms_content`
--

DROP TABLE IF EXISTS `plg_cms_content`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_cms_content` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `author` int(11) DEFAULT NULL,
  `title` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `content` longtext COLLATE utf8_unicode_ci,
  `category` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT '0',
  `layout` int(11) DEFAULT '0',
  `date_published` date DEFAULT NULL,
  `last_modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `slug` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plg_cms_content`
--

LOCK TABLES `plg_cms_content` WRITE;
/*!40000 ALTER TABLE `plg_cms_content` DISABLE KEYS */;
INSERT INTO `plg_cms_content` VALUES (1,1,'Elan Experimental Rally Car','&lt;p&gt;&lt;span style=&quot;caret-color: rgb(33, 37, 41); color: rgb(33, 37, 41); font-family: &amp;quot;Open Sans&amp;quot;, -apple-system, BlinkMacSystemFont, &amp;quot;Segoe UI&amp;quot;, Roboto, &amp;quot;Helvetica Neue&amp;quot;, Arial, sans-serif, &amp;quot;Apple Color Emoji&amp;quot;, &amp;quot;Segoe UI Emoji&amp;quot;, &amp;quot;Segoe UI Symbol&amp;quot;; font-size: 13px; background-color: rgb(252, 252, 252);&quot;&gt;Brian Walton&lt;/span&gt;&lt;/p&gt;&lt;p style=&quot;color: rgb(33, 37, 41); font-family: &amp;quot;Open Sans&amp;quot;, -apple-system, BlinkMacSystemFont, &amp;quot;Segoe UI&amp;quot;, Roboto, &amp;quot;Helvetica Neue&amp;quot;, Arial, sans-serif, &amp;quot;Apple Color Emoji&amp;quot;, &amp;quot;Segoe UI Emoji&amp;quot;, &amp;quot;Segoe UI Symbol&amp;quot;; font-size: 13px;&quot;&gt;A UNIQUE WORKS RALLY ELAN&lt;/p&gt;&lt;p style=&quot;color: rgb(33, 37, 41); font-family: &amp;quot;Open Sans&amp;quot;, -apple-system, BlinkMacSystemFont, &amp;quot;Segoe UI&amp;quot;, Roboto, &amp;quot;Helvetica Neue&amp;quot;, Arial, sans-serif, &amp;quot;Apple Color Emoji&amp;quot;, &amp;quot;Segoe UI Emoji&amp;quot;, &amp;quot;Segoe UI Symbol&amp;quot;; font-size: 13px;&quot;&gt;It&#039;s certainly been fun. I&#039;ve knocked on many an interesting door in the last twelve months researching my recently acquired Elan. It is now time to give you all the chance to discover what I have found. I think you will find the history rather interesting.&lt;/p&gt;&lt;p style=&quot;color: rgb(33, 37, 41); font-family: &amp;quot;Open Sans&amp;quot;, -apple-system, BlinkMacSystemFont, &amp;quot;Segoe UI&amp;quot;, Roboto, &amp;quot;Helvetica Neue&amp;quot;, Arial, sans-serif, &amp;quot;Apple Color Emoji&amp;quot;, &amp;quot;Segoe UI Emoji&amp;quot;, &amp;quot;Segoe UI Symbol&amp;quot;; font-size: 13px;&quot;&gt;I acquired the little coupe S3 Elan from Ray Wilkinson in early February 1996. It arrived back in Meadowbank looking rather sorry on the back of a rented trailer. My new pride and joy needed a little TLC. I was glad Ray didn&#039;t live in Avondale as the collection of spiders within the car frightened the hell out of Cerise my wife. For the next two months the car was pushed back and forth out of my garage mainly I suspect to impress the neighbors. I had convinced myself that the first six months were to be spent researching the cars history. It has actually taken twelve months and I now feel that I have finally reached some light at the end of the long tunnel. Clear and Telecom must have loved me. I called the most amazing collection of interesting telephone numbers. I can still remember phoning the only number for Friswell in London. A dear old lady of at least 105 answered. She really did think it was her maker and had never heard of Auckland. Letters were also sent far and wide. I would send out 10 letters at a time and average a fair 20% response. &quot;If you are still alive please answer&quot; seemed to create the best response. So, to all you doubting Thomas&#039;s out there in Lotus land here goes. I will be working backwards through the years.&lt;/p&gt;&lt;p style=&quot;color: rgb(33, 37, 41); font-family: &amp;quot;Open Sans&amp;quot;, -apple-system, BlinkMacSystemFont, &amp;quot;Segoe UI&amp;quot;, Roboto, &amp;quot;Helvetica Neue&amp;quot;, Arial, sans-serif, &amp;quot;Apple Color Emoji&amp;quot;, &amp;quot;Segoe UI Emoji&amp;quot;, &amp;quot;Segoe UI Symbol&amp;quot;; font-size: 13px;&quot;&gt;New Zealand History...the last of the story&lt;/p&gt;&lt;p style=&quot;color: rgb(33, 37, 41); font-family: &amp;quot;Open Sans&amp;quot;, -apple-system, BlinkMacSystemFont, &amp;quot;Segoe UI&amp;quot;, Roboto, &amp;quot;Helvetica Neue&amp;quot;, Arial, sans-serif, &amp;quot;Apple Color Emoji&amp;quot;, &amp;quot;Segoe UI Emoji&amp;quot;, &amp;quot;Segoe UI Symbol&amp;quot;; font-size: 13px;&quot;&gt;The car was exported to New Zealand from England on 4th December 1978. It was imported by a Mr. Barry Freeman who still lives in Birkdale/ Auckland. It had 50,000 miles on its clock when it arrived and was registered JD 1888 on 21st May 1979. Barry had fitted Revolution wheels in England necessitating extra wheel archers. The car was eventually resprayed a bright red after it was landed. A house was on the horizon for Barry in late 1979 and so the Elan was sold to Gayle and Harley Oliver on October 1979 (57,600 miles on the clock). I&#039;m sure a number of the older members of Club Lotus remember it at that stage. Rattle your walkers if you can recollect the red little coupe! It has appeared in a few club magazines including the Taccoc Bespoke (Sept 1981) and LOTUS WORLD (Feb 1989). The car was used a few times in club events and eventually it was sold to Malcolme Campbell and Ray Wilkinson on 17th July 1987 (63,808 miles on the clock). A substantial rebuild of the Twin Cam motor was completed just before this point. Ray took over full ownership of JD 1888 on 11th March 1988 and eventually it gained the personalized plate ELAN 1. Ray completed a total of only 36 miles in the Elan and it was nestled within his other collection of Loti in outback Hunua. The car, as I have already explained came my way in February 1996.&lt;/p&gt;&lt;p style=&quot;color: rgb(33, 37, 41); font-family: &amp;quot;Open Sans&amp;quot;, -apple-system, BlinkMacSystemFont, &amp;quot;Segoe UI&amp;quot;, Roboto, &amp;quot;Helvetica Neue&amp;quot;, Arial, sans-serif, &amp;quot;Apple Color Emoji&amp;quot;, &amp;quot;Segoe UI Emoji&amp;quot;, &amp;quot;Segoe UI Symbol&amp;quot;; font-size: 13px;&quot;&gt;&quot;A letter from Ray Badcock at Lotus dated 20th March 1979 and sent to Barry Freeman stated that this Elan was once a Works Experimental Rally Elan.&quot; The hunt was on when I received the vehicle to find out if this &#039;experimental story&#039; was true....&lt;/p&gt;',2,1,2,'2021-03-16','2021-03-16 17:54:52','elan-experimental-rally-car');
/*!40000 ALTER TABLE `plg_cms_content` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plg_cms_layouts`
--

DROP TABLE IF EXISTS `plg_cms_layouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_cms_layouts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `layout` text COLLATE utf8_unicode_ci,
  `def` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plg_cms_layouts`
--

LOCK TABLES `plg_cms_layouts` WRITE;
/*!40000 ALTER TABLE `plg_cms_layouts` DISABLE KEYS */;
INSERT INTO `plg_cms_layouts` VALUES (1,'Default','<div class=\"row\"><div class=\"col-12\"><!>con<!></div></div>',1),(2,'Blog','<div class=\"row\">\r\n  <div class=\"col-md-12\">\r\n    <h2 align=\"center\"><!>nam<!></h2>\r\n  </div>\r\n  <div class=\"col-md-12 text-center\">\r\n    <!>cat<!>\r\n  </div>\r\n</div>\r\n<div class=\"row\">\r\n  <div class=\"col-md-6\">Author:\r\n    <strong><!>aut<!></strong>\r\n  </div>\r\n  <div class=\"col-md-6\">Updated:\r\n    <strong><!>mod<!></strong>\r\n  </div>\r\n</div>\r\n<div class=\"row\">\r\n  <div class=\"col-md-12\">\r\n    <!>con<!>\r\n  </div>\r\n</div>',0);
/*!40000 ALTER TABLE `plg_cms_layouts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plg_cms_notices`
--

DROP TABLE IF EXISTS `plg_cms_notices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_cms_notices` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `dismissed` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plg_cms_notices`
--

LOCK TABLES `plg_cms_notices` WRITE;
/*!40000 ALTER TABLE `plg_cms_notices` DISABLE KEYS */;
/*!40000 ALTER TABLE `plg_cms_notices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plg_cms_settings`
--

DROP TABLE IF EXISTS `plg_cms_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_cms_settings` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `parser` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plg_cms_settings`
--

LOCK TABLES `plg_cms_settings` WRITE;
/*!40000 ALTER TABLE `plg_cms_settings` DISABLE KEYS */;
INSERT INTO `plg_cms_settings` VALUES (1,'usersc/plugins/cms/content.php');
/*!40000 ALTER TABLE `plg_cms_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plg_cms_tags`
--

DROP TABLE IF EXISTS `plg_cms_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_cms_tags` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `article` int(11) unsigned NOT NULL,
  `tag` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plg_cms_tags`
--

LOCK TABLES `plg_cms_tags` WRITE;
/*!40000 ALTER TABLE `plg_cms_tags` DISABLE KEYS */;
/*!40000 ALTER TABLE `plg_cms_tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plg_cms_widgets`
--

DROP TABLE IF EXISTS `plg_cms_widgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_cms_widgets` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `widget_type` tinyint(1) DEFAULT '0',
  `title` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `file` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `content` text COLLATE utf8_unicode_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plg_cms_widgets`
--

LOCK TABLES `plg_cms_widgets` WRITE;
/*!40000 ALTER TABLE `plg_cms_widgets` DISABLE KEYS */;
/*!40000 ALTER TABLE `plg_cms_widgets` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-09-11  5:02:34
