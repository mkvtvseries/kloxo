<?php
//    Kloxo, Hosting Control Panel
//
//    Copyright (C) 2000-2009	LxLabs
//    Copyright (C) 2009-2010	LxCenter
//
//    This program is free software: you can redistribute it and/or modify
//    it under the terms of the GNU Affero General Public License as
//    published by the Free Software Foundation, either version 3 of the
//    License, or (at your option) any later version.
//
//    This program is distributed in the hope that it will be useful,
//    but WITHOUT ANY WARRANTY; without even the implied warranty of
//    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//    GNU Affero General Public License for more details.
//
//    You should have received a copy of the GNU Affero General Public License
//    along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
include_once "../install_common.php";
$downloadserver = "http://download.lxcenter.org/";
lxins_main();

function lxins_main()
{
	global $argv;
	global $downloadserver;
	$opt = parse_opt($argv);
	$dir_name=dirname(__FILE__);
	$installtype = $opt['install-type'];
	$dbroot = isset($opt['db-rootuser'])? $opt['db-rootuser']: "root";
	$dbpass = isset($opt['db-rootpassword'])? $opt['db-rootpassword']: "";
	$osversion = find_os_version();
	$arch = `arch`;
	$arch = trim($arch);

	if (!char_search_beg($osversion, "centos") && !char_search_beg($osversion, "rhel")) {
		print("Kloxo is only supported on CentOS 5 and RHEL 5\n");
		exit;
	}

	if(file_exists("/usr/local/lxlabs/kloxo")) {
		print("Kloxo seems already installed do you wish to continue?(No/Yes):\n");
		flush();
		$stdin = fopen('php://stdin','r');
		$argq = fread($stdin, 5);
		$arg=trim($argq);
		if(!($arg=='y' ||$arg=='yes'||$arg=='Yes'||$arg=='Y'||$arg=='YES')) {
			print("Installation Aborted.\n");
			exit;
		}
	} else {
		print("Kloxo is using AGPL-V3.0 License, do you agree with the terms? (No/Yes):\n");
		flush();
		$stdin = fopen('php://stdin','r');
		$argq = fread($stdin, 5);
		$arg=trim($argq);
			if(!($arg=='y' ||$arg=='yes'||$arg=='Yes'||$arg=='Y'||$arg=='YES')) {
				print("You did not agreed the AGPL-V3.0 License terms.\n");
				print("Installation aborted.\n\n");
				exit;
			} else {
				dprint("Installing Kloxo...\n\n");
			}
	}

	print("Adding System users and groups (nouser, nogroup and lxlabs, lxlabs)\n");
	system("groupadd nogroup");
	system("useradd nouser -g nogroup -s '/sbin/nologin'");
	system("groupadd lxlabs");
	system("useradd lxlabs -g lxlabs -s '/sbin/nologin'");

	print("Installing LxCenter yum repository for updates\n");
	install_yum_repo($osversion);
	
	print("Removing sendmail, exim, vsftpd, postfix, vpopmail, qmail,\n");
	print("Removing lxphp, lxzend, pure-ftpd and imap\n");
	exec("rpm -e --nodeps sendmail");
	exec("rpm -e --nodeps exim");
	exec("rpm -e --nodeps sendmail vsftpd postfix vpopmail qmail lxphp lxzend pure-ftpd imap > /dev/null 2>&1");


	$package = array("php-mysql", "which", "gcc-c++", "php-imap", "php-pear", "php-devel", "lxlighttpd", "httpd", "mod_ssl", "zip","unzip","lxphp", "mysql", "mysql-server",  "mysqlclient10", "lxzend","curl","autoconf","automake","libtool", "bogofilter", "gcc", "cpp", "openssl", "pure-ftpd");

	$list = implode(" ", $package);
	while (true) {
		print("Installing packages $list...\n");
		system("PATH=\$PATH:/usr/sbin yum -y install $list", $return_value);
		if (file_exists("/usr/local/lxlabs/ext/php/php")) {
			break;
		} else {
			print("Yum Gave Error... Trying Again...\n");
		}
	}
	print("Prepare installation directory\n");
	system("mkdir -p /usr/local/lxlabs/kloxo");
	chdir("/usr/local/lxlabs/kloxo");
	system("mkdir -p /usr/local/lxlabs/kloxo/log");
	@ unlink("kloxo-current.zip");
	print("Downloading latest Kloxo release\n");
	system("wget ".$downloadserver."download/kloxo/production/kloxo/kloxo-current.zip");
	print("\n\nInstalling Kloxo.....\n\n");
	system("unzip -oq kloxo-current.zip", $return); 

	if ($return) {
		print("Unzipping the core Failed.. Most likely it is corrupted. Report it at http://forum.lxcenter.org/\n");
		exit;
	}

	unlink("kloxo-current.zip");
	system("chown -R lxlabs:lxlabs /usr/local/lxlabs/");
	chdir("/usr/local/lxlabs/kloxo/httpdocs/");
	system("service mysqld start");

	if ($installtype !== 'slave') {
		check_default_mysql($dbroot, $dbpass);
	}
	$mypass = password_gen();
	
	print("Prepare defaults and configurations...\n");
	system("/usr/local/lxlabs/ext/php/php $dir_name/installall.php");
	our_file_put_contents("/etc/sysconfig/spamassassin", "SPAMDOPTIONS=\" -v -d -p 783 -u lxpopuser\"");
	print("Creating Vpopmail database...\n");
	system("sh $dir_name/vpop.sh $dbroot \"$dbpass\" lxpopuser $mypass");
	system("chmod -R 755 /var/log/httpd/");
	system("chmod -R 755 /var/log/httpd/fpcgisock >/dev/null 2>&1");
	system("mkdir -p /var/log/kloxo/");
	system("mkdir -p /var/log/news");
	system("ln -sf /var/qmail/bin/sendmail /usr/sbin/sendmail");
	system("ln -sf /var/qmail/bin/sendmail /usr/lib/sendmail");
	system("echo `hostname` > /var/qmail/control/me");
	system("service qmail restart >/dev/null 2>&1 &");
	system("service courier-imap restart >/dev/null 2>&1 &");
	$dbfile="/home/kloxo/httpd/webmail/horde/scripts/sql/create.mysql.sql";
	if(file_exists($dbfile)) {
		if($dbpass == "") {
			system("mysql -u $dbroot  <$dbfile");
		} else {
			system("mysql -u $dbroot -p$dbpass <$dbfile");
		}
	}
	system("mkdir -p /home/kloxo/httpd");
	chdir("/home/kloxo/httpd");
	@ unlink("skeleton-disable.zip");
	system("chown -R lxlabs:lxlabs /home/kloxo/httpd");
    system("/etc/init.d/kloxo restart >/dev/null 2>&1 &");
	chdir("/usr/local/lxlabs/kloxo/httpdocs/");
	system("/usr/local/lxlabs/ext/php/php /usr/local/lxlabs/kloxo/bin/install/create.php --install-type=$installtype --db-rootuser=$dbroot --db-rootpassword=$dbpass");
	system("/script/centos5-postpostupgrade");

print("Congratulations. Kloxo has been installed succesfully on your server as $installtype \n");
	if ($installtype === 'master') {
		print("You can connect to the server at https://<ip-address>:7777 or http://<ip-address>:7778\n");
		print("Please note that first is secure ssl connection, while the second is normal one.\n");
		print("The login and password are 'admin' 'admin'. After Logging in, you will have to change your password to something more secure\n");
		print("We hope you will find managing your hosting with Kloxo refreshingly pleasurable, and also we wish you all the success on your hosting venture\n");
		print("Thanks for choosing Kloxo to manage your hosting, and allowing us to be of service\n");
	} else {
		print("You should open the port 7779 on this server, since this is used for the communication between master and slave\n");
		print("To access this slave, to go admin->servers->add server, give the ip/machine name of this server. The password is 'admin'. The slave will appear in the list of slaves, and you can access it just like you access localhost\n\n");
	}

}
