<?php
/*
 * system_camanager.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2004-2016 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2008 Shrew Soft Inc
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

##|+PRIV
##|*IDENT=page-system-camanager
##|*NAME=System: CA Manager
##|*DESCR=Allow access to the 'System: CA Manager' page.
##|*MATCH=system_camanager.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("certs.inc");

$ca_methods = array(
	"existing" => gettext("Import an existing Certificate Authority"),
	"internal" => gettext("Create an internal Certificate Authority"),
	"intermediate" => gettext("Create an intermediate Certificate Authority"));

$ca_keylens = array("512", "1024", "2048", "3072", "4096", "7680", "8192", "15360", "16384");
$openssl_digest_algs = array("sha1", "sha224", "sha256", "sha384", "sha512", "whirlpool");

if (isset($_POST['id']) && is_numericint($_POST['id'])) {
	$id = $_POST['id'];
}

if (!is_array($config['ca'])) {
	$config['ca'] = array();
}

$a_ca =& $config['ca'];

if (!is_array($config['cert'])) {
	$config['cert'] = array();
}

$a_cert =& $config['cert'];

if (!is_array($config['crl'])) {
	$config['crl'] = array();
}

$a_crl =& $config['crl'];

if ($_POST['act']) {
	$act = $_POST['act'];
}

if ($act == "del") {

	if (!isset($a_ca[$id])) {
		pfSenseHeader("system_camanager.php");
		exit;
	}

	/* Only remove CA reference when deleting. It can be reconnected if a new matching CA is imported */
	$index = count($a_cert) - 1;
	for (;$index >= 0; $index--) {
		if ($a_cert[$index]['caref'] == $a_ca[$id]['refid']) {
			unset($a_cert[$index]['caref']);
		}
	}

	/* Remove any CRLs for this CA, there is no way to recover the connection once the CA has been removed. */
	$index = count($a_crl) - 1;
	for (;$index >= 0; $index--) {
		if ($a_crl[$index]['caref'] == $a_ca[$id]['refid']) {
			unset($a_crl[$index]);
		}
	}

	$name = $a_ca[$id]['descr'];
	unset($a_ca[$id]);
	write_config();
	$savemsg = sprintf(gettext("Certificate Authority %s and its CRLs (if any) successfully deleted."), htmlspecialchars($name));
	pfSenseHeader("system_camanager.php");
	exit;
}

if ($act == "edit") {
	if (!$a_ca[$id]) {
		pfSenseHeader("system_camanager.php");
		exit;
	}
	$pconfig['descr']  = $a_ca[$id]['descr'];
	$pconfig['refid']  = $a_ca[$id]['refid'];
	$pconfig['cert']   = base64_decode($a_ca[$id]['crt']);
	$pconfig['serial'] = $a_ca[$id]['serial'];
	if (!empty($a_ca[$id]['prv'])) {
		$pconfig['key'] = base64_decode($a_ca[$id]['prv']);
	}
}

if ($act == "new") {
	$pconfig['method'] = $_POST['method'];
	$pconfig['keylen'] = "2048";
	$pconfig['digest_alg'] = "sha256";
	$pconfig['lifetime'] = "3650";
	$pconfig['dn_commonname'] = "internal-ca";
}

if ($act == "exp") {

	if (!$a_ca[$id]) {
		pfSenseHeader("system_camanager.php");
		exit;
	}

	$exp_name = urlencode("{$a_ca[$id]['descr']}.crt");
	$exp_data = base64_decode($a_ca[$id]['crt']);
	$exp_size = strlen($exp_data);

	header("Content-Type: application/octet-stream");
	header("Content-Disposition: attachment; filename={$exp_name}");
	header("Content-Length: $exp_size");
	echo $exp_data;
	exit;
}

if ($act == "expkey") {

	if (!$a_ca[$id]) {
		pfSenseHeader("system_camanager.php");
		exit;
	}

	$exp_name = urlencode("{$a_ca[$id]['descr']}.key");
	$exp_data = base64_decode($a_ca[$id]['prv']);
	$exp_size = strlen($exp_data);

	header("Content-Type: application/octet-stream");
	header("Content-Disposition: attachment; filename={$exp_name}");
	header("Content-Length: $exp_size");
	echo $exp_data;
	exit;
}

if ($_POST && ($_POST['save'] == 'Save')) {

	unset($input_errors);
	$input_errors = array();
	$pconfig = $_POST;

	/* input validation */
	if ($pconfig['method'] == "existing") {
		$reqdfields = explode(" ", "descr cert");
		$reqdfieldsn = array(
			gettext("Descriptive name"),
			gettext("Certificate data"));
		if ($_POST['cert'] && (!strstr($_POST['cert'], "BEGIN CERTIFICATE") || !strstr($_POST['cert'], "END CERTIFICATE"))) {
			$input_errors[] = gettext("This certificate does not appear to be valid.");
		}
		if ($_POST['key'] && strstr($_POST['key'], "ENCRYPTED")) {
			$input_errors[] = gettext("Encrypted private keys are not yet supported.");
		}
		if (!$input_errors && !empty($_POST['key']) && cert_get_modulus($_POST['cert'], false) != prv_get_modulus($_POST['key'], false)) {
			$input_errors[] = gettext("The submitted private key does not match the submitted certificate data.");
		}
	}
	if ($pconfig['method'] == "internal") {
		$reqdfields = explode(" ",
			"descr keylen lifetime dn_country dn_state dn_city ".
			"dn_organization dn_email dn_commonname");
		$reqdfieldsn = array(
			gettext("Descriptive name"),
			gettext("Key length"),
			gettext("Lifetime"),
			gettext("Distinguished name Country Code"),
			gettext("Distinguished name State or Province"),
			gettext("Distinguished name City"),
			gettext("Distinguished name Organization"),
			gettext("Distinguished name Email Address"),
			gettext("Distinguished name Common Name"));
	}
	if ($pconfig['method'] == "intermediate") {
		$reqdfields = explode(" ",
			"descr caref keylen lifetime dn_country dn_state dn_city ".
			"dn_organization dn_email dn_commonname");
		$reqdfieldsn = array(
			gettext("Descriptive name"),
			gettext("Signing Certificate Authority"),
			gettext("Key length"),
			gettext("Lifetime"),
			gettext("Distinguished name Country Code"),
			gettext("Distinguished name State or Province"),
			gettext("Distinguished name City"),
			gettext("Distinguished name Organization"),
			gettext("Distinguished name Email Address"),
			gettext("Distinguished name Common Name"));
	}

	do_input_validation($_POST, $reqdfields, $reqdfieldsn, $input_errors);
	if ($pconfig['method'] != "existing") {
		/* Make sure we do not have invalid characters in the fields for the certificate */
		if (preg_match("/[\?\>\<\&\/\\\"\']/", $_POST['descr'])) {
			array_push($input_errors, gettext("The field 'Descriptive Name' contains invalid characters."));
		}

		for ($i = 0; $i < count($reqdfields); $i++) {
			if ($reqdfields[$i] == 'dn_email') {
				if (preg_match("/[\!\#\$\%\^\(\)\~\?\>\<\&\/\\\,\"\']/", $_POST["dn_email"])) {
					array_push($input_errors, gettext("The field 'Distinguished name Email Address' contains invalid characters."));
				}
			} else if ($reqdfields[$i] == 'dn_commonname') {
				if (preg_match("/[\!\@\#\$\%\^\(\)\~\?\>\<\&\/\\\,\"\']/", $_POST["dn_commonname"])) {
					array_push($input_errors, gettext("The field 'Distinguished name Common Name' contains invalid characters."));
				}
			} else if (($reqdfields[$i] != "descr") && preg_match("/[\!\@\#\$\%\^\(\)\~\?\>\<\&\/\\\,\.\"\']/", $_POST["$reqdfields[$i]"])) {
				array_push($input_errors, sprintf(gettext("The field '%s' contains invalid characters."), $reqdfieldsn[$i]));
			}
		}
		if (!in_array($_POST["keylen"], $ca_keylens)) {
			array_push($input_errors, gettext("Please select a valid Key Length."));
		}
		if (!in_array($_POST["digest_alg"], $openssl_digest_algs)) {
			array_push($input_errors, gettext("Please select a valid Digest Algorithm."));
		}
	}

	/* save modifications */
	if (!$input_errors) {
		$ca = array();
		if (!isset($pconfig['refid']) || empty($pconfig['refid'])) {
			$ca['refid'] = uniqid();
		} else {
			$ca['refid'] = $pconfig['refid'];
		}

		if (isset($id) && $a_ca[$id]) {
			$ca = $a_ca[$id];
		}

		$ca['descr'] = $pconfig['descr'];

		if ($act == "edit") {
			$ca['descr']  = $pconfig['descr'];
			$ca['refid']  = $pconfig['refid'];
			$ca['serial'] = $pconfig['serial'];
			$ca['crt']	  = base64_encode($pconfig['cert']);
			if (!empty($pconfig['key'])) {
				$ca['prv']	  = base64_encode($pconfig['key']);
			}
		} else {
			$old_err_level = error_reporting(0); /* otherwise openssl_ functions throw warnings directly to a page screwing menu tab */
			if ($pconfig['method'] == "existing") {
				ca_import($ca, $pconfig['cert'], $pconfig['key'], $pconfig['serial']);
			} else if ($pconfig['method'] == "internal") {
				$dn = array(
					'countryName' => $pconfig['dn_country'],
					'stateOrProvinceName' => $pconfig['dn_state'],
					'localityName' => $pconfig['dn_city'],
					'organizationName' => $pconfig['dn_organization'],
					'emailAddress' => $pconfig['dn_email'],
					'commonName' => $pconfig['dn_commonname']);
				if (!empty($pconfig['dn_organizationalunit'])) {
					$dn['organizationalUnitName'] = $pconfig['dn_organizationalunit'];
				}
				if (!ca_create($ca, $pconfig['keylen'], $pconfig['lifetime'], $dn, $pconfig['digest_alg'])) {
					while ($ssl_err = openssl_error_string()) {
						$input_errors = array();
						array_push($input_errors, "openssl library returns: " . $ssl_err);
					}
				}
			} else if ($pconfig['method'] == "intermediate") {
				$dn = array(
					'countryName' => $pconfig['dn_country'],
					'stateOrProvinceName' => $pconfig['dn_state'],
					'localityName' => $pconfig['dn_city'],
					'organizationName' => $pconfig['dn_organization'],
					'emailAddress' => $pconfig['dn_email'],
					'commonName' => $pconfig['dn_commonname']);
				if (!empty($pconfig['dn_organizationalunit'])) {
					$dn['organizationalUnitName'] = $pconfig['dn_organizationalunit'];
				}
				if (!ca_inter_create($ca, $pconfig['keylen'], $pconfig['lifetime'], $dn, $pconfig['caref'], $pconfig['digest_alg'])) {
					while ($ssl_err = openssl_error_string()) {
						$input_errors = array();
						array_push($input_errors, "openssl library returns: " . $ssl_err);
					}
				}
			}
			error_reporting($old_err_level);
		}

		if (isset($id) && $a_ca[$id]) {
			$a_ca[$id] = $ca;
		} else {
			$a_ca[] = $ca;
		}

		if (!$input_errors) {
			write_config();
		}

		pfSenseHeader("system_camanager.php");
	}
}

$pgtitle = array(gettext("System"), gettext("Certificate Manager"), gettext("CAs"));
$pglinks = array("", "system_camanager.php", "system_camanager.php");

if ($act == "new" || $act == "edit" || $act == gettext("Save") || $input_errors) {
	$pgtitle[] = gettext('Edit');
	$pglinks[] = "@self";
}
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

// Load valid country codes
$dn_cc = array();
if (file_exists("/etc/ca_countries")) {
	$dn_cc_file=file("/etc/ca_countries");
	foreach ($dn_cc_file as $line) {
		if (preg_match('/^(\S*)\s(.*)$/', $line, $matches)) {
			$dn_cc[$matches[1]] = $matches[1];
		}
	}
}

$tab_array = array();
$tab_array[] = array(gettext("CAs"), true, "system_camanager.php");
$tab_array[] = array(gettext("Certificates"), false, "system_certmanager.php");
$tab_array[] = array(gettext("Certificate Revocation"), false, "system_crlmanager.php");
display_top_tabs($tab_array);

if (!($act == "new" || $act == "edit" || $act == gettext("Save") || $input_errors)) {
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Certificate Authorities')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
		<table class="table table-striped table-hover table-rowdblclickedit">
			<thead>
				<tr>
					<th><?=gettext("Name")?></th>
					<th><?=gettext("Internal")?></th>
					<th><?=gettext("Issuer")?></th>
					<th><?=gettext("Certificates")?></th>
					<th><?=gettext("Distinguished Name")?></th>
					<th><?=gettext("In Use")?></th>
					<th><?=gettext("Actions")?></th>
				</tr>
			</thead>
			<tbody>
<?php
foreach ($a_ca as $i => $ca):
	$name = htmlspecialchars($ca['descr']);
	$subj = cert_get_subject($ca['crt']);
	$issuer = cert_get_issuer($ca['crt']);
	list($startdate, $enddate) = cert_get_dates($ca['crt']);
	if ($subj == $issuer) {
		$issuer_name = gettext("self-signed");
	} else {
		$issuer_name = gettext("external");
	}
	$subj = htmlspecialchars($subj);
	$issuer = htmlspecialchars($issuer);
	$certcount = 0;

	$issuer_ca = lookup_ca($ca['caref']);
	if ($issuer_ca) {
		$issuer_name = $issuer_ca['descr'];
	}

	foreach ($a_cert as $cert) {
		if ($cert['caref'] == $ca['refid']) {
			$certcount++;
		}
	}

	foreach ($a_ca as $cert) {
		if ($cert['caref'] == $ca['refid']) {
			$certcount++;
		}
	}
?>
				<tr>
					<td><?=$name?></td>
					<td><i class="fa fa-<?= (!empty($ca['prv'])) ? "check" : "times" ; ?>"></i></td>
					<td><i><?=$issuer_name?></i></td>
					<td><?=$certcount?></td>
					<td>
						<?=$subj?>
						<br />
						<small>
							<?=gettext("Valid From")?>: <b><?=$startdate ?></b><br /><?=gettext("Valid Until")?>: <b><?=$enddate ?></b>
						</small>
					</td>
					<td class="text-nowrap">
						<?php if (is_openvpn_server_ca($ca['refid'])): ?>
							<?=gettext("OpenVPN Server")?><br/>
						<?php endif?>
						<?php if (is_openvpn_client_ca($ca['refid'])): ?>
							<?=gettext("OpenVPN Client")?><br/>
						<?php endif?>
						<?php if (is_ipsec_peer_ca($ca['refid'])): ?>
							<?=gettext("IPsec Tunnel")?><br/>
						<?php endif?>
						<?php if (is_ldap_peer_ca($ca['refid'])): ?>
							<?=gettext("LDAP Server")?>
						<?php endif?>
					</td>
					<td class="text-nowrap">
						<a class="fa fa-pencil"	title="<?=gettext("Edit CA")?>"	href="system_camanager.php?act=edit&amp;id=<?=$i?>" usepost></a>
						<a class="fa fa-certificate"	title="<?=gettext("Export CA")?>"	href="system_camanager.php?act=exp&amp;id=<?=$i?>" usepost></a>
					<?php if ($ca['prv']): ?>
						<a class="fa fa-key"	title="<?=gettext("Export key")?>"	href="system_camanager.php?act=expkey&amp;id=<?=$i?>" usepost></a>
					<?php endif?>
					<?php if (!ca_in_use($ca['refid'])): ?>
						<a class="fa fa-trash" 	title="<?=gettext("Delete CA and its CRLs")?>"	href="system_camanager.php?act=del&amp;id=<?=$i?>" usepost ></a>
					<?php endif?>
					</td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	</div>
</div>

<nav class="action-buttons">
	<a href="?act=new" class="btn btn-success btn-sm" usepost>
		<i class="fa fa-plus icon-embed-btn"></i>
		<?=gettext("Add")?>
	</a>
</nav>
<?php
	include("foot.inc");
	exit;
}

$form = new Form;
//$form->setAction('system_camanager.php?act=edit');
if (isset($id) && $a_ca[$id]) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

if ($act == "edit") {
	$form->addGlobal(new Form_Input(
		'refid',
		null,
		'hidden',
		$pconfig['refid']
	));
}

$section = new Form_Section('Create / Edit CA');

$section->addInput(new Form_Input(
	'descr',
	'*Descriptive name',
	'text',
	$pconfig['descr']
));

if (!isset($id) || $act == "edit") {
	$section->addInput(new Form_Select(
		'method',
		'*Method',
		$pconfig['method'],
		$ca_methods
	))->toggles();
}

$form->add($section);

$section = new Form_Section('Existing Certificate Authority');
$section->addClass('toggle-existing collapse');

$section->addInput(new Form_Textarea(
	'cert',
	'*Certificate data',
	$pconfig['cert']
))->setHelp('Paste a certificate in X.509 PEM format here.');

$section->addInput(new Form_Textarea(
	'key',
	'Certificate Private Key (optional)',
	$pconfig['key']
))->setHelp('Paste the private key for the above certificate here. This is '.
	'optional in most cases, but is required when generating a '.
	'Certificate Revocation List (CRL).');

$section->addInput(new Form_Input(
	'serial',
	'Serial for next certificate',
	'number',
	$pconfig['serial']
))->setHelp('Enter a decimal number to be used as the serial number for the next '.
	'certificate to be created using this CA.');

$form->add($section);

$section = new Form_Section('Internal Certificate Authority');
$section->addClass('toggle-internal', 'toggle-intermediate', 'collapse');

$allCas = array();
foreach ($a_ca as $ca) {
	if (!$ca['prv']) {
			continue;
	}

	$allCas[ $ca['refid'] ] = $ca['descr'];
}

$group = new Form_Group('*Signing Certificate Authority');
$group->addClass('toggle-intermediate', 'collapse');
$group->add(new Form_Select(
	'caref',
	null,
	$pconfig['caref'],
	$allCas
));
$section->add($group);

$section->addInput(new Form_Select(
	'keylen',
	'*Key length (bits)',
	$pconfig['keylen'],
	array_combine($ca_keylens, $ca_keylens)
));

$section->addInput(new Form_Select(
	'digest_alg',
	'*Digest Algorithm',
	$pconfig['digest_alg'],
	array_combine($openssl_digest_algs, $openssl_digest_algs)
))->setHelp('NOTE: It is recommended to use an algorithm stronger than SHA1 '.
	'when possible.');

$section->addInput(new Form_Input(
	'lifetime',
	'*Lifetime (days)',
	'number',
	$pconfig['lifetime']
));

$section->addInput(new Form_Select(
	'dn_country',
	'*Country Code',
	$pconfig['dn_country'],
	$dn_cc
));

$section->addInput(new Form_Input(
	'dn_state',
	'*State or Province',
	'text',
	$pconfig['dn_state'],
	['placeholder' => 'e.g. Texas']
));

$section->addInput(new Form_Input(
	'dn_city',
	'*City',
	'text',
	$pconfig['dn_city'],
	['placeholder' => 'e.g. Austin']
));

$section->addInput(new Form_Input(
	'dn_organization',
	'*Organization',
	'text',
	$pconfig['dn_organization'],
	['placeholder' => 'e.g. My Company Inc']
));

$section->addInput(new Form_Input(
	'dn_organizationalunit',
	'Organizational Unit',
	'text',
	$pconfig['dn_organizationalunit'],
	['placeholder' => 'e.g. My Department Name (optional)']
));

$section->addInput(new Form_Input(
	'dn_email',
	'*Email Address',
	'email',
	$pconfig['dn_email'],
	['placeholder' => 'e.g. admin@mycompany.com']
));

$section->addInput(new Form_Input(
	'dn_commonname',
	'*Common Name',
	'text',
	$pconfig['dn_commonname'],
	['placeholder' => 'e.g. internal-ca']
));

$form->add($section);

print $form;

$internal_ca_count = 0;
foreach ($a_ca as $ca) {
	if ($ca['prv']) {
		$internal_ca_count++;
	}
}

include('foot.inc');
?>
