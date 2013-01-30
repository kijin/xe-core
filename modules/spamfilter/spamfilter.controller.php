<?php
    /**
     * @class  spamfilterController
     * @author NHN (developers@xpressengine.com)
     * @brief The controller class for the spamfilter module
     **/

    class spamfilterController extends spamfilter {

        /**
         * @brief Initialization
         **/
        function init() {
        }

        /**
         * @brief Call this function in case you need to stop the spam filter's usage during the batch work
         **/
        function setAvoidLog() {
            $_SESSION['avoid_log'] = true;
        }

        /**
         * @brief The routine process to check the time it takes to store a document, when writing it, and to ban IP/word
         **/
        function triggerInsertDocument(&$obj) {
            if($_SESSION['avoid_log']) return new Object();
            // Check the login status, login information, and permission
            $is_logged = Context::get('is_logged');
            $logged_info = Context::get('logged_info');
            $grant = Context::get('grant');
            // In case logged in, check if it is an administrator
            if($is_logged) {
                if($logged_info->is_admin == 'Y') return new Object();
                if($grant->manager) return new Object();
            }

            $oFilterModel = &getModel('spamfilter');
            // Check if the IP is prohibited
            $output = $oFilterModel->isDeniedIP();
            if(!$output->toBool()) return $output;
            // Check if there is a ban on the word
            $text = $obj->title.$obj->content;
            $output = $oFilterModel->isDeniedWord($text);
            if(!$output->toBool()) return $output;
            // Check the specified time beside the modificaiton time
            if($obj->document_srl == 0){
                $output = $oFilterModel->checkLimited();
                if(!$output->toBool()) return $output;
            }
            // Save a log
            $this->insertLog();

            return new Object();
        }

        /**
         * @brief The routine process to check the time it takes to store a comment, and to ban IP/word
         **/
        function triggerInsertComment(&$obj) {
            if($_SESSION['avoid_log']) return new Object();
            // Check the login status, login information, and permission
            $is_logged = Context::get('is_logged');
            $logged_info = Context::get('logged_info');
            $grant = Context::get('grant');
            // In case logged in, check if it is an administrator
            if($is_logged) {
                if($logged_info->is_admin == 'Y') return new Object();
                if($grant->manager) return new Object();
            }

            $oFilterModel = &getModel('spamfilter');
            // Check if the IP is prohibited
            $output = $oFilterModel->isDeniedIP();
            if(!$output->toBool()) return $output;
            // Check if there is a ban on the word
            $text = $obj->content;
            $output = $oFilterModel->isDeniedWord($text);
            if(!$output->toBool()) return $output;
            // If the specified time check is not modified
            if(!$obj->__isupdate){
                $output = $oFilterModel->checkLimited();
                if(!$output->toBool()) return $output;
            }
            unset($obj->__isupdate);
            // Save a log
            $this->insertLog();

            return new Object();
        }

        /**
         * @brief Inspect the trackback creation time and IP
         **/
        function triggerInsertTrackback(&$obj) {
            if($_SESSION['avoid_log']) return new Object();

            $oFilterModel = &getModel('spamfilter');
            // Confirm if the trackbacks have been added more than once to your document
            $output = $oFilterModel->isInsertedTrackback($obj->document_srl);
            if(!$output->toBool()) return $output;
            
            // Check if the IP is prohibited
            $output = $oFilterModel->isDeniedIP();
            if(!$output->toBool()) return $output;
            // Check if there is a ban on the word
            $text = $obj->blog_name.$obj->title.$obj->excerpt.$obj->url;
            $output = $oFilterModel->isDeniedWord($text);
            if(!$output->toBool()) return $output;
            // Start Filtering
            $oTrackbackModel = &getModel('trackback');
            $oTrackbackController = &getController('trackback');

            list($ipA,$ipB,$ipC,$ipD) = explode('.',$_SERVER['REMOTE_ADDR']);
            $ipaddress = $ipA.'.'.$ipB.'.'.$ipC;
            // In case the title and the blog name are indentical, investigate the IP address of the last 6 hours, delete and ban it.
            if($obj->title == $obj->excerpt) {
                $oTrackbackController->deleteTrackbackSender(60*60*6, $ipaddress, $obj->url, $obj->blog_name, $obj->title, $obj->excerpt);
                $this->insertIP($ipaddress.'.*', 'AUTO-DENIED : trackback.insertTrackback');
                return new Object(-1,'msg_alert_trackback_denied');
            }
            // If trackbacks have been registered by one C-class IP address more than once for the last 30 minutes, ban the IP address and delete all the posts
            /* ?�스???�경??감안?�여 ?�단 ??부분�? ?�작?��? ?�도�?주석 처리
            $count = $oTrackbackModel->getRegistedTrackback(30*60, $ipaddress, $obj->url, $obj->blog_name, $obj->title, $obj->excerpt);
            if($count > 1) {
                $oTrackbackController->deleteTrackbackSender(3*60, $ipaddress, $obj->url, $obj->blog_name, $obj->title, $obj->excerpt);
                $this->insertIP($ipaddress.'.*');
                return new Object(-1,'msg_alert_trackback_denied');
            }
            */

            return new Object();
        }

        /**
         * @brief IP registration
         * The registered IP address is considered as a spammer
         **/
        function insertIP($ipaddress_list, $description = null) {
			$ipaddress_list = str_replace("\r","",$ipaddress_list);
			$ipaddress_list = explode("\n",$ipaddress_list);
			foreach($ipaddress_list as $ipaddressValue) {
				preg_match("/(\d{1,3}(?:.(\d{1,3}|\*)){3})\s*(\/\/(.*)\s*)?/",$ipaddressValue,$matches);
				if($ipaddress=trim($matches[1])) {
					$args->ipaddress = $ipaddress;
					if(!$description && $matches[4]) $args->description = $matches[4];
					else $args->description = $description;
				}

				$output = executeQuery('spamfilter.insertDeniedIP', $args);

				if(!$output->toBool()) return $output;
			}
			return $output;

        }

        /**
         * @brief Log registration
         * Register the newly accessed IP address in the log. In case the log interval is withing a certain time,
         * register it as a spammer
         **/
        function insertLog() {
            $output = executeQuery('spamfilter.insertLog');
            return $output;
        }
    }
?>
