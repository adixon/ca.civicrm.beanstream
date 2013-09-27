-- install sql for Beanstream Services extension, create a table to hold custom codes

CREATE TABLE `civicrm_beanstream_customer_codes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT  COMMENT 'Custom code Id',
  `customer_code` varchar(255) NOT NULL COMMENT 'Customer code returned from Beanstream',
  `ip` varchar(255) DEFAULT NULL COMMENT 'Last IP from which this customer code was accessed or created',
  `expiry` varchar(4) DEFAULT NULL COMMENT 'CC expiry yymm',
  `cid` int(10) unsigned DEFAULT '0' COMMENT 'CiviCRM contact id',
  `email` varchar(255) DEFAULT NULL COMMENT 'Customer-constituent Email address',
  `recur_id` int(10) unsigned DEFAULT '0' COMMENT 'CiviCRM recurring_contribution table id',
  PRIMARY KEY ( `id` ),
  UNIQUE INDEX (`customer_code`),
  KEY (`cid`),
  KEY (`email`),
  KEY (`recur_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Table to store customer codes';

CREATE TABLE `civicrm_beanstream_request_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT  COMMENT 'Request Log Id',
  `invoice_num` varchar(255) NOT NULL COMMENT 'Invoice number being sent to Beanstream',
  `ip` varchar(255) DEFAULT NULL COMMENT 'IP from which this request originated',
  `cc` varchar(4) DEFAULT NULL COMMENT 'CC last four digits',
  `customer_code` varchar(255) COMMENT 'Customer code if used',
  `total` decimal(20,2) DEFAULT NULL COMMENT 'Charge amount request',
  `request_datetime` datetime COMMENT 'Date time of request',
  PRIMARY KEY ( `id` ),
  KEY (`invoice_num`),
  KEY (`cc`),
  KEY (`request_datetime`),
  KEY (`customer_code`),
  KEY (`total`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Table for request log';

CREATE TABLE `civicrm_beanstream_response_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT  COMMENT 'Response Log Id',
  `invoice_num` varchar(255) NOT NULL COMMENT 'Invoice number sent to Beanstream',
  `auth_result` varchar(255) NOT NULL COMMENT 'Authorization string returned from Beanstream',
  `remote_id` varchar(255) NOT NULL COMMENT 'Beanstream-internal transaction id',
  `response_datetime` datetime COMMENT 'Date time of response',
  PRIMARY KEY ( `id` ),
  KEY (`invoice_num`),
  KEY (`auth_result`),
  KEY (`remote_id`),
  KEY (`response_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Table for response log';
