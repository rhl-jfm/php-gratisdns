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

    /**
     * GratisDNS constructor.
     *
     * @param string $username
     * @param string $password
     * @param bool   $debug
     *
     * @throws Exception
     */
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

    /**
     * Get list of all domains in account
     *
     * @return string[]
     */
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

    /**
     * Get the first resource record that matches parameters
     *
     * @param string $domain
     * @param string $type
     * @param string $host
     *
     * @return array|null
     */
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

    /**
     * Get resource record with specified ID (or null)
     *
     * @param string $domain
     * @param int    $id
     *
     * @return array|null
     */
    public function getRecordById(string $domain, int $id): ?array
    {
        if (empty($this->records[$domain])) {
            $this->getRecords($domain);
        }

        return $this->lookupRecord($id);
    }

    /**
     * Get all resource records for domain
     *
     * @param string $domain
     *
     * @return array
     */
    public function getRecords(string $domain): array
    {
        $html = $this->performGetRequest('dns_primary_changeDNSsetup', ['user_domain' => $domain]);
        $htmldom = new simple_html_dom();
        $htmldom->load($html);

        $this->records[$domain] = [];
        foreach ($htmldom->find('div[class=dns-records]') as $div) {
            /** @var simple_html_dom_node $div */
            $type = strtok($div->find('div[class=d-flex] h2', 0)->innertext(), ' ');
            if (! in_array($type, ['A', 'AAAA', 'CNAME', 'MX', 'AFSDB', 'TXT', 'NS', 'SRV', 'SSHFP'])) {
                continue;
            }
            foreach ($div->find('tbody tr') as $tr) {
                /** @var simple_html_dom_node $tr */
                $btngroup = $tr->find('div[class=btn-group]', 0);
                if (empty($btngroup)) {
                    # Skip header rows
                    continue;
                }
                if (! isset($this->records[$domain][$type])) {
                    $this->records[$domain][$type] = [];
                }
                $editlink = $tr->find('a[class=btn]', 0);
                if ($editlink) {
                    parse_str($editlink->attr['href'], $editparams);
                    $recordid = (int) $editparams['id'];
                } else {
                    $recordid = null;
                }
                $tds = $tr->find('td');

                $host = (in_array($type, [
                    'NS',
                    'SRV',
                    'TXT',
                    'MX',
                    'A',
                    'AAAA'
                ])) ? count($this->records[$domain][$type]) : utf8_encode($tds[0]->innertext());
                $this->records[$domain][$type][$host]['type'] = $type;
                if ($recordid) {
                    $this->records[$domain][$type][$host]['recordid'] = $recordid;
                }
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
                        # Work around inconsistency of output
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

    /**
     * Create domain
     *
     * @param string $domain
     *
     * @return void
     */
    public function createDomain(string $domain): void
    {
        $this->performPostRequest('dns_primary_createprimaryandsecondarydnsforthisdomain', ['user_domain' => $domain]);
    }

    /**
     * Delete domain
     *
     * @param string $domain
     *
     * @return void
     */
    public function deleteDomain(string $domain): void
    {
        $this->performPostRequest('dns_primary_delete', ['user_domain' => $domain]);
    }

    /**
     * Create resource record
     *
     * @param string         $domain
     * @param string         $type
     * @param string         $host
     * @param string         $data
     * @param integer        $ttl
     * @param string         $preference_or_algorithm
     * @param integer|string $weight_or_type
     * @param integer        $port
     *
     * @return void
     */
    function createRecord(
        string $domain,
        string $type,
        string $host,
        string $data,
        int $ttl = 43200,
        ?string $preference_or_algorithm = null,
        $weight_or_type = false,
        ?int $port = null
    ): void {
        $post_array = [
            'user_domain' => $domain,
            'name'        => $host,
            'ttl'         => $ttl,
        ];
        switch ($type) {
            case 'A':
            case 'AAAA':
                $post_array['ip'] = $data;
                break;
            case 'CNAME':
                $post_array['name'] = $host;
                $post_array['cname'] = $data;
                break;
            case 'MX':
            case 'AFSDB':
                $post_array['name'] = $host;
                $post_array['exchanger'] = $data;
                $post_array['preference'] = $preference_or_algorithm;
                break;
            case 'TXT':
                $post_array['name'] = $host;
                $post_array['txtdata'] = $data;
                break;
            case 'NS':
                $post_array['name'] = $host;
                $post_array['nsdname'] = $data;
                break;
            case 'SRV':
                $post_array['name'] = $host;
                $post_array['target'] = $data;
                $post_array['priority'] = $preference_or_algorithm;
                $post_array['weight'] = $weight_or_type;
                $post_array['port'] = $port;
                break;
            case 'SSHFP':
                // Parameter names do not make sense. Oh well.
                $post_array['name'] = $host;
                $post_array['sshfp'] = $data;
                $post_array['algorithm'] = $preference_or_algorithm;
                $post_array['type'] = $weight_or_type;
                break;
            default:
                throw new InvalidArgumentException("Unsupported record type $type");
        }
        $this->performPostRequest('dns_primary_record_added_' . strtolower($type), $post_array);
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
     * @throws DomainException If record not found
     * @return void
     */
    function deleteRecord(string $domain, int $recordid): void
    {
        $record = $this->getRecordById($domain, $recordid);
        if (! $record) {
            throw new DomainException("Record with id $recordid not found for domain $domain");
        }

        $type = $record['type'];

        $params = [
            'user_domain' => $domain,
            'recordid'    => $recordid,
        ];

        $this->performPostRequest('dns_primary_delete_' . strtolower($type), $params);
    }

    /**
     * Send POST request to GratisDNS and (optionally) check output for errors
     *
     * @param string $action
     * @param array  $args
     * @param bool   $ignore_errors
     *
     * @return string Response HTML
     */
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

        if (! $ignore_errors && ($return_code < 200 || $return_code >= 400)) {
            throw new RuntimeException("Action $action (POST) failed with return code $return_code");
        }

        if (! $ignore_errors && ! $this->checkResponse($html)) {
            throw new RuntimeException("Action $action (POST) reported error: {$this->last_error_message}");
        }

        return $html;
    }

    /**
     * Send GET request to GratisDNS and (optionally) check output for errors
     *
     * @param string $action
     * @param array  $args
     * @param bool   $ignore_errors
     *
     * @return string Response HTML
     */
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

        if (! $ignore_errors && ($return_code < 200 || $return_code >= 400)) {
            throw new RuntimeException("Action $action (GET) failed with return code $return_code");
        }

        if (! $ignore_errors && ! $this->checkResponse($html)) {
            throw new RuntimeException("Action $action (GET) reported error: {$this->last_error_message}");
        }

        return $html;
    }

    /**
     * Check response HTML for errors and set error string
     *
     * @param string $html
     *
     * @return bool Does response indicate success?
     */
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

        return ! $error_element;
    }

    /**
     * Search record cache for record with given ID
     *
     * @param int $recordid
     *
     * @return array|null
     */
    private function lookupRecord(int $recordid): ?array
    {
        if (0 == $recordid) {
            // We dont want to match NS records
            throw new InvalidArgumentException('Record id may not be 0');
        }

        foreach ($this->records as $domain) {
            foreach ($domain as $type) {
                foreach ($type as $record) {
                    if ($recordid == $record['recordid']) {
                        return $record;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Perform login to GratisDNS
     *
     * @return void
     */
    private function performLogin(): void
    {
        $html = $this->performPostRequest('',
            ['login' => $this->username, 'password' => $this->password, 'action' => 'logmein'],
            true);

        if (! $this->checkResponse($html)) {
            throw new RuntimeException('Login failed');
        }
    }

    /**
     * Get value of TXT record (helper for getRecords)
     *
     * @param string $domain
     * @param int    $recordid
     *
     * @return string
     */
    private function getRawTxtData(string $domain, int $recordid): string
    {
        $html = $this->performGetRequest('dns_primary_record_edit_txt', ['id' => $recordid, 'user_domain' => $domain]);
        $htmldom = new simple_html_dom();
        $htmldom->load($html);

        return trim($htmldom->find('input[name=txtdata]', 0)->attr['value']);
    }
}
