<?php
/**
 * ProjectName: php-gratisdns
 * Plugin URI: http://github.com/kasperhartwich/php-gratisdns
 * Description: Altering your DNS records at GratisDNS
 *
 * @author  Kasper Hartwich <kasper@hartwich.net>
 * @package php-gratisdns
 * @version 0.9.3
 */

class GratisDNS
{
    /**
     * @var string
     */
    private $username;

    /**
     * @var string
     */
    private $password;

    /**
     * @var string
     */
    private $cookie_file;

    /**
     * @var string
     */
    private $admin_url = 'https://admin.gratisdns.com';

    /**
     * @var string[]
     */
    public $domains = null;

    /**
     * @var array
     */
    public $records = null;

    /**
     * @var bool
     */
    private $debug = false;

    /**
     * @var string|null
     */
    private $last_error_message;

    function __construct(string $username, string $password, bool $debug = false)
    {
        require_once __DIR__ . '/simple_html_dom.php';
        $this->username = $username;
        $this->password = $password;
        $this->debug = $debug;

        $this->cookie_file = tempnam('/tmp', 'cookie');
        register_shutdown_function('unlink', $this->cookie_file);

        if (! function_exists('curl_init')) {
            throw new Exception('php cURL extension must be installed and enabled');
        }

        $this->performLogin();
    }

    public function getDomains(): array
    {
        $html = $this->performGetRequest('dns_primary_changeDNSsetup');

        $htmldom = new simple_html_dom();
        $htmldom->load($html);

        $this->domains = [];
        foreach ($htmldom->find('a') as $a) {
            if (preg_match('/action=dns_primary_changeDNSsetup&user_domain=([^&]+)/', $a->attr['href'], $m)) {
                $this->domains[] = $m[1];
            }
        }

        return $this->domains;
    }

    public function getRecordByDomain(string $domain, string $type, string $host): ?array
    {
        if (empty($this->records[$domain])) {
            $this->getRecords($domain);
        }
        $domaininfo = $this->records[$domain];
        if ($domaininfo) {
            if (isset($domaininfo[$type])) {
                foreach ($domaininfo[$type] as $record) {
                    if ($record['host'] === $host) {
                        return $record;
                    }
                }

                return null;
            } else {
                return null;
            }
        } else {
            return null;
        }
    }

    public function getRecordById(string $domain, int $id): ?array
    {
        if (empty($this->records[$domain])) {
            $this->getRecords($domain);
        }

        return $this->lookupRecord($id);
    }

    public function getRecords(string $domain): array
    {
        $html = $this->performGetRequest('dns_primary_changeDNSsetup', ['user_domain' => $domain]);
        $htmldom = new simple_html_dom();
        $htmldom->load($html);

        $this->records[$domain] = [];
        foreach ($htmldom->find('div[class=dns-records]') as $div) {
            /** @var simple_html_dom_node $div */
            $type = strtok($div->find('div[class=d-flex] h2', 0)->innertext(), ' ');
            if (!in_array($type, ['A', 'AAAA', 'CNAME', 'MX', 'AFSDB', 'TXT', 'NS', 'SRV', 'SSHFP'])) {
                continue;
            }
            foreach ($div->find('tbody tr') as $tr) {
                /** @var simple_html_dom_node $tr */
                $editlink = $tr->find('a[class=btn]', 0);
                if (empty($editlink)) {
                    # Skip header rows
                    continue;
                }
                if (! isset($this->records[$domain][$type])) {
                    $this->records[$domain][$type] = [];
                }
                parse_str($editlink->attr['href'], $editparams);
                $recordid = (int)$editparams['id'];

                $tds = $tr->find('td');

                $host = (in_array($type, [
                    'NS',
                    'SRV',
                    'TXT',
                    'MX',
                ])) ? count($this->records[$domain][$type]) : utf8_encode($tds[0]->innertext());
                $this->records[$domain][$type][$host]['type'] = $type;
                $this->records[$domain][$type][$host]['recordid'] = $recordid;
                $this->records[$domain][$type][$host]['host'] = utf8_encode($tds[0]->innertext());
                $this->records[$domain][$type][$host]['data'] = utf8_encode($tds[1]->innertext());
                switch ($type) {
                    case 'TXT':
                        # Data field may be truncated on the overview page ...
                        if (substr($this->records[$domain][$type][$host]['data'], -3, 3) == '...') {
                            $this->records[$domain][$type][$host]['data'] = $this->getRawTxtData($domain, $recordid);
                        }
                    case 'A':
                    case 'AAAA':
                    case 'CNAME':
                    case 'NS':
                        $this->records[$domain][$type][$host]['ttl'] = (int) $tds[2]->innertext();
                        break;
                    case 'MX':
                    case 'AFSDB':
                        # Work around inconsistency in output
                        $this->records[$domain][$type][$host]['data'] = trim(strip_tags(utf8_encode($tds[1]->innertext())));
                        $this->records[$domain][$type][$host]['preference'] = $tds[2]->innertext();
                        $this->records[$domain][$type][$host]['ttl'] = (int) $tds[3]->innertext();
                        break;
                    case 'SRV':
                        $this->records[$domain][$type][$host]['priority'] = $tds[2]->innertext();
                        $this->records[$domain][$type][$host]['weight'] = (int) $tds[3]->innertext();
                        $this->records[$domain][$type][$host]['port'] = (int) $tds[4]->innertext();
                        $this->records[$domain][$type][$host]['ttl'] = (int) $tds[5]->innertext();
                        break;
                    case 'SSHFP':
                        //Not supported
                        break;
                        // TODO: Support CAA records
                }
            }
        }

        return $this->records[$domain];
    }

    public function createDomain(string $domain): void
    {
        $this->performPostRequest('dns_primary_createprimaryandsecondarydnsforthisdomain', [ 'user_domain' => $domain ]);
    }

    public function deleteDomain(string $domain): void
    {
        $this->performPostRequest('dns_primary_delete', [ 'user_domain' => $domain ]);
    }

    /**
     *
     * @param string  $domain
     * @param string  $type
     * @param string  $host
     * @param string  $data
     * @param integer $ttl Use with caution. Some guess work is involved and we may end up setting TTL on the wrong record
     * @param string  $preference
     * @param integer $weight
     * @param integer $port
     *
     * @return boolean
     */
    function createRecord(
        $domain,
        $type,
        $host,
        $data,
        $ttl = false,
        $preference = false,
        $weight = false,
        $port = false
    ) {
        $post_array = [
            'action'      => 'add' . strtolower($type) . 'record',
            'user_domain' => $domain,
        ];
        switch ($type) {
            case 'A':
            case 'AAAA':
                $post_array['host'] = $host;
                $post_array['ip'] = $data;
                break;
            case 'CNAME':
                $post_array['host'] = $host;
                $post_array['kname'] = $data;
                break;
            case 'MX':
            case 'AFSDB':
                $post_array['host'] = $host;
                $post_array['exchanger'] = $data;
                $post_array['preference'] = $preference;
                break;
            case 'TXT':
            case 'NS':
                $post_array['leftRR'] = $host;
                $post_array['rightRR'] = $data;
                break;
            case 'SRV':
                $post_array['host'] = $host;
                $post_array['exchanger'] = $data;
                $post_array['preference'] = $preference;
                $post_array['weight'] = $weight;
                $post_array['port'] = $port;
                break;
            case 'SSHFP':
                $post_array['host'] = $host;
                $post_array['rightRR'] = $data;
                $post_array['preference'] = $preference;
                $post_array['weight'] = $weight;
                break;
        }
        $html = $this->performPostRequest('todo', $post_array);
        $response = $this->checkResponse($html);
        if ($response && $ttl) {
            // Here be Dragons, recommend not to use this feature.
            $record = $this->getRecordByDomain($domain, $type, $host);

            return $this->updateRecord($domain, $record['recordid'], $type, $host, $data, $ttl);
        } else {
            return $response;
        }
    }

    /**
     * @param string  $domain
     * @param integer $recordid
     * @param string  $type
     * @param string  $host
     * @param type    $data
     * @param type    $ttl
     *
     * @return boolean
     */
    public function updateRecord($domain, $recordid, $type = false, $host = false, $data = false, $ttl = false)
    {
        $post_array = [
            'action'      => 'makechangesnow',
            'user_domain' => $domain,
            'recordid'    => $recordid,
        ];

        if ($type) {
            $post_array['type'] = $type;
        } else {
            $record = $this->getRecordById($domain, $recordid);
            if (! $record) {
                return false;
            }
            $post_array['type'] = $record['type'];
            $type = $record['type'];
        }
        if ($host) {
            $post_array['host'] = $host;
        } else {
            $record = $this->getRecordById($domain, $recordid);
            if (! $record) {
                return false;
            }
            $post_array['host'] = $record['host'];
        }
        switch ($type) {
            case 'A':
            case 'AAAA':
            case 'MX':
            case 'CNAME':
            case 'TXT':
            case 'AFSDB':
                if (! $ttl) {
                    $record = $this->getRecordById($domain, $recordid);
                    if (! $record) {
                        return false;
                    }
                    $ttl = $record['ttl'];
                }
                $post_array['new_data'] = $data;
                $post_array['new_ttl'] = $ttl;
                break;
            case 'SRV':
                $post_array['new_ttl'] = $ttl;
                break;
            case 'NS':
                return $this->error('Updating NS record is not supported by GratisDNS.');
            case 'SSHFP':
                return $this->error('Not supported.');
        }
        $html = $this->performPostRequest('todo', $post_array);

        return $this->checkResponse($html);
    }

    function applyTemplate($domain, $template, $ttl = false)
    {
        switch ($template) {
            //Feel free to fork and add other templates. :)
            case 'googleapps':
                $this->createRecord($domain, 'MX', $domain, 'aspmx.l.google.com', $ttl, 1);
                $this->createRecord($domain, 'MX', $domain, 'alt1.aspmx.l.google.com', $ttl, 5);
                $this->createRecord($domain, 'MX', $domain, 'alt2.aspmx.l.google.com', $ttl, 5);
                $this->createRecord($domain, 'MX', $domain, 'aspmx2.googlemail.com', $ttl, 10);
                $this->createRecord($domain, 'MX', $domain, 'aspmx3.googlemail.com', $ttl, 10);
                $this->createRecord($domain, 'CNAME', 'mail.' . $domain, 'ghs.google.com', $ttl);
                $this->createRecord($domain, 'CNAME', 'start.' . $domain, 'ghs.google.com', $ttl);
                $this->createRecord($domain, 'CNAME', 'calendar.' . $domain, 'ghs.google.com', $ttl);
                $this->createRecord($domain, 'CNAME', 'docs.' . $domain, 'ghs.google.com', $ttl);
                $this->createRecord($domain, 'CNAME', 'sites.' . $domain, 'ghs.google.com', $ttl);
                $this->createRecord($domain, 'SRV', '_jabber._tcp.' . $domain, 'xmpp-server.l.google.com', $ttl, 5, 0,
                    5269);
                $this->createRecord($domain, 'SRV', '_jabber._tcp.' . $domain, 'xmpp-server1.l.google.com', $ttl, 20, 0,
                    5269);
                $this->createRecord($domain, 'SRV', '_jabber._tcp.' . $domain, 'xmpp-server2.l.google.com', $ttl, 20, 0,
                    5269);
                $this->createRecord($domain, 'SRV', '_jabber._tcp.' . $domain, 'xmpp-server3.l.google.com', $ttl, 20, 0,
                    5269);
                $this->createRecord($domain, 'SRV', '_jabber._tcp.' . $domain, 'xmpp-server4.l.google.com', $ttl, 20, 0,
                    5269);
                $this->createRecord($domain, 'SRV', '_xmpp-server._tcp.' . $domain, 'xmpp-server.l.google.com', $ttl, 5,
                    0, 5269);
                $this->createRecord($domain, 'SRV', '_xmpp-server._tcp.' . $domain, 'xmpp-server1.l.google.com', $ttl,
                    20, 0, 5269);
                $this->createRecord($domain, 'SRV', '_xmpp-server._tcp.' . $domain, 'xmpp-server2.l.google.com', $ttl,
                    20, 0, 5269);
                $this->createRecord($domain, 'SRV', '_xmpp-server._tcp.' . $domain, 'xmpp-server3.l.google.com', $ttl,
                    20, 0, 5269);
                $this->createRecord($domain, 'SRV', '_xmpp-server._tcp.' . $domain, 'xmpp-server4.l.google.com', $ttl,
                    20, 0, 5269);
                break;
            default:
                return error('Unknown template');
        }
    }

    /**
     * Delete a record
     *
     * @param string  $domain   Domain name
     * @param integer $recordid Record to be deleted
     *
     * @return
     */
    function deleteRecord(string $domain, int $recordid): void
    {
        $record = $this->getRecordById($domain, $recordid);
        if (!$record) {
            throw new RuntimeException("Record with id $recordid not fount for domain $domain");
        }

        $type = $record['type'];

        $params = [
            'user_domain' => $domain,
            'recordid'    => $recordid
        ];

        $this->performPostRequest('dns_primary_delete_' . lc($type), $params);
    }

    private function performPostRequest(string $action, array $args = [], bool $ignore_errors = false): string
    {
        $url = $this->admin_url . ($action ? "?action=" . urlencode($action) : '');

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $this->cookie_file);
        curl_setopt($curl, CURLOPT_COOKIEFILE, $this->cookie_file);

        curl_setopt($curl, CURLOPT_URL, $url);

        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $args);

        $html = curl_exec($curl);
        $return_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($this->debug) {
            var_dump("POST: $url -> $return_code");
        }

        curl_close($curl);

        if (!$ignore_errors && ($return_code < 200 || $return_code >= 400)) {
            throw new RuntimeException("Action $action (POST) failed with return code $return_code");
        }

        if (!$ignore_errors && !$this->checkResponse($html)) {
            throw new RuntimeException("Action $action (POST) reported error: {$this->last_error_message}");
        }

        return $html;
    }

    private function performGetRequest(string $action, array $args = [], bool $ignore_errors = false): string
    {
        $args['action'] = $action;
        $url = $this->admin_url . ($args ? "?" . http_build_query($args) : '');

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $this->cookie_file);
        curl_setopt($curl, CURLOPT_COOKIEFILE, $this->cookie_file);

        curl_setopt($curl, CURLOPT_URL, $url);

        $html = curl_exec($curl);
        $return_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($this->debug) {
            var_dump("GET: $url -> $return_code");
        }

        curl_close($curl);

        if (!$ignore_errors && ($return_code < 200 || $return_code >= 400)) {
            throw new RuntimeException("Action $action (GET) failed with return code $return_code");
        }

        if (!$ignore_errors && !$this->checkResponse($html)) {
            throw new RuntimeException("Action $action (GET) reported error: {$this->last_error_message}");
        }

        return $html;
    }

    private function checkResponse(string $html): bool
    {
        $htmldom = new simple_html_dom();
        $htmldom->load($html);
        $error_element = $htmldom->find('td[class=table-danger],div[class=alert]', 0);

        if ($error_element) {
            /** @var simple_html_dom_node $error_element */
            $this->last_error_message = $error_element->innertext();
        } else {
            $this->last_error_message = null;
        }
        return !$error_element;
    }

    private function lookupRecord(int $recordid): ?array
    {
        if (0 == $recordid) {
            // We dont want to match NS records
            throw new InvalidArgumentException('Record id may not be 0');
        }

        foreach ($this->records as $domain) {
            foreach ($domain as $type) {
                foreach ($type as $host) {
                    if ($recordid == $host['recordid']) {
                        return $host;
                    }
                }
            }
        }

        return null;
    }

    private function performLogin(): void
    {
        $html = $this->performPostRequest('',
            [ 'login' => $this->username, 'password' => $this->password, 'action' => 'logmein'],
            true);

        if (!$this->checkResponse($html)) {
            throw new RuntimeException('Login failed');
        }
    }

    private function getRawTxtData(string $domain, int $recordid): string
    {
        $html = $this->performGetRequest('dns_primary_record_edit_txt', ['id' => $recordid, 'user_domain' => $domain]);
        $htmldom = new simple_html_dom();
        $htmldom->load($html);
        return trim($htmldom->find('input[name=txtdata]', 0)->attr['value']);
    }
}
