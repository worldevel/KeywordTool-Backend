<?php

/*
 * Copyright 2018 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace App\Http\Controllers;

// require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Error;
use Google\Auth\CredentialsLoader;
use Google\Auth\OAuth2;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use UnexpectedValueException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use GetOpt\GetOpt;
use Google\Ads\GoogleAds\Examples\Utils\ArgumentNames;
use Google\Ads\GoogleAds\Examples\Utils\ArgumentParser;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V12\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V12\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\V12\GoogleAdsException;
use Google\Ads\GoogleAds\Util\V12\ResourceNames;
use Google\Ads\GoogleAds\V12\Enums\KeywordPlanNetworkEnum\KeywordPlanNetwork;
use Google\Ads\GoogleAds\V12\Errors\GoogleAdsError;
use Google\Ads\GoogleAds\V12\Services\GenerateKeywordIdeaResult;
use Google\Ads\GoogleAds\V12\Services\KeywordAndUrlSeed;
use Google\Ads\GoogleAds\V12\Services\KeywordSeed;
use Google\Ads\GoogleAds\V12\Services\UrlSeed;
use Google\ApiCore\ApiException;

use Illuminate\Support\Facades\Mail;
use App\Mail\TestEmail;

/**
 * This example will create an OAuth2 refresh token for the Google Ads API. This example works with
 * both web and desktop app OAuth client ID types.
 *
 * We highly recommend running this example locally, since you won't need to generate refresh tokens
 * very often and you can avoid issue of port settings that may occur when using a Docker container.
 *
 * IMPORTANT: For web app clients types, you must add "http://127.0.0.1" to the "Authorized
 * redirect URIs" list in your Google Cloud Console project before running this example. Desktop app
 * client types do not require the local redirect to be explicitly configured in the console.
 *
 * <p>This example will start a basic server that listens for requests at `http://127.0.0.1:PORT`,
 * where `PORT` is dynamically assigned.
 */
class GenerateUserCredentials extends Controller
{
  /**
   * @var string the OAuth2 scope for the Google Ads API
   * @see https://developers.google.com/google-ads/api/docs/oauth/internals#scope
   */

  private const CUSTOMER_ID = '1916120994';
  // Location criteria IDs. For example, specify 21167 for New York. For more information
  // on determining this value, see
  // https://developers.google.com/adwords/api/docs/appendix/geotargeting.
  private const LOCATION_ID_1 = '21167';
  // private const LOCATION_ID_2 = 'INSERT_LOCATION_ID_2_HERE';

  // A language criterion ID. For example, specify 1000 for English. For more information
  // on determining this value, see
  // https://developers.google.com/adwords/api/docs/appendix/codes-formats#languages.
  private const LANGUAGE_ID = '1000';

  private const KEYWORD_TEXT_1 = 'c#';
  // private const KEYWORD_TEXT_2 = 'INSERT_KEYWORD_TEXT_2_HERE';

  // Optional: Specify a URL string related to your business to generate ideas.
  private const PAGE_URL = null;


  private const SCOPE = 'https://www.googleapis.com/auth/adwords';

  /**
   * @var string the Google OAuth2 authorization URI for OAuth2 requests
   * @see https://developers.google.com/identity/protocols/OAuth2InstalledApp#step-2-send-a-request-to-googles-oauth-20-server
   */
  private const AUTHORIZATION_URI = 'https://accounts.google.com/o/oauth2/v2/auth';

  /**
   * @var string the OAuth2 call back IP address.
   */
  private const OAUTH2_CALLBACK_IP_ADDRESS = '127.0.0.1';

  public static function main()
  {

    if (!class_exists(HttpServer::class)) {
      echo 'Please install "react/http" package to be able to run this example';
    }

    // Creates a socket for localhost with random port. Port 0 is used to tell the SocketServer
    // to create a server with a random port.
    $socket = new SocketServer(self::OAUTH2_CALLBACK_IP_ADDRESS . ':0');
    // To fill in the values below, generate a client ID and client secret from the Google Cloud
    // Console (https://console.cloud.google.com) by creating credentials for either a web or
    // desktop app OAuth client ID.
    // If using a web application, add the following to its "Authorized redirect URIs":

    // To fill in the values below, generate a client ID and client secret from the Google Cloud
    // Console (https://console.cloud.google.com) by creating credentials for either a web or
    // desktop app OAuth client ID.
    // If using a web application, add the following to its "Authorized redirect URIs":
    //   http://127.0.0.1
    // print 'Enter your OAuth2 client ID here: ';
    $clientId = "1097675652759-mdn9cl01513mkcr476k88g041mle58vm.apps.googleusercontent.com";

    // print 'Enter your OAuth2 client secret here: ';
    $clientSecret = "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";

    $redirectUrl = str_replace('tcp:', 'http:', $socket->getAddress());
    $oauth2 = new OAuth2(
      [
        'clientId' => $clientId,
        'clientSecret' => $clientSecret,
        'authorizationUri' => self::AUTHORIZATION_URI,
        'redirectUri' => $redirectUrl,
        'tokenCredentialUri' => CredentialsLoader::TOKEN_CREDENTIAL_URI,
        'scope' => self::SCOPE,
        // Create a 'state' token to prevent request forgery. See
        // https://developers.google.com/identity/protocols/OpenIDConnect#createxsrftoken
        // for details.
        'state' => sha1(openssl_random_pseudo_bytes(1024))
      ]
    );

    $authToken = null;

    $server = new HttpServer(
      function (ServerRequestInterface $request) use ($oauth2, &$authToken) {
        // Stops the server after tokens are retrieved.
        if (!is_null($authToken)) {
          Loop::stop();
        }

        // Check if the requested path is the one set as the redirect URI. We add '/' here
        // so the parse_url method can function correctly, since it cannot detect the URI
        // without '/' at the end, which is the case for the value of getRedirectUri().
        if (
          $request->getUri()->getPath()
          !== parse_url($oauth2->getRedirectUri() . '/', PHP_URL_PATH)
        ) {
          return new Response(
            404,
            ['Content-Type' => 'text/plain'],
            'Page not found'
          );
        }

        // Exit if the state is invalid to prevent request forgery.
        $state = $request->getQueryParams()['state'];
        if (empty($state) || ($state !== $oauth2->getState())) {
          throw new UnexpectedValueException(
            "The state is empty or doesn't match expected one." . PHP_EOL
          );
        };

        // Set the authorization code and fetch refresh and access tokens.
        $code = $request->getQueryParams()['code'];
        $oauth2->setCode($code);
        $authToken = $oauth2->fetchAuthToken();

        $refreshToken = $authToken['refresh_token'];
        print 'Your refresh token is: ' . $refreshToken . PHP_EOL;

        $propertiesToCopy = '[GOOGLE_ADS]' . PHP_EOL;
        $propertiesToCopy .= 'developerToken = "INSERT_DEVELOPER_TOKEN_HERE"' . PHP_EOL;
        $propertiesToCopy .=  <<<EOD
          ; Required for manager accounts only: Specify the login customer ID used to authenticate API calls.
          ; This will be the customer ID of the authenticated manager account. You can also specify this later
          ; in code if your application uses multiple manager account + OAuth pairs.
          ; loginCustomerId = "INSERT_LOGIN_CUSTOMER_ID_HERE"
          EOD;
        $propertiesToCopy .= PHP_EOL . '[OAUTH2]' . PHP_EOL;
        $propertiesToCopy .= "clientId = \"{$oauth2->getClientId()}\"" . PHP_EOL;
        $propertiesToCopy .= "clientSecret = \"{$oauth2->getClientSecret()}\"" . PHP_EOL;
        $propertiesToCopy .= "refreshToken = \"$refreshToken\"" . PHP_EOL;

        print 'Copy the text below into a file named "google_ads_php.ini" in your home '
          . 'directory, and replace "INSERT_DEVELOPER_TOKEN_HERE" with your developer '
          . 'token:' . PHP_EOL;
        print PHP_EOL . $propertiesToCopy;

        return new Response(
          200,
          ['Content-Type' => 'text/plain'],
          'Your refresh token has been fetched. Check the console output for '
            . 'further instructions.'
        );
      }
    );
    $server->listen($socket);
    printf(
      'Log into the Google account you use for Google Ads and visit the following URL '
        . 'in your web browser: %1$s%2$s%1$s%1$s',
      PHP_EOL,
      $oauth2->buildFullAuthorizationUri(['access_type' => 'offline'])
    );
  }

  public static function get_search_volume(Request $request)
  {
    // $customerId = $request->input('customerId');
    $customerId = '1501167472';
    // $languageId = $request->input('languageId');
    $languageId = 1000;
    // $pageUrl = $request->input('pageUrl');
    $pageUrl = 'https://github.com';
    $keyword = $request->input('keyword');
    $locationIds = ['21167'];
    $keywords = [$keyword];
    // Generate a refreshable OAuth2 credential for authentication.
    $oAuth2Credential = (new OAuth2TokenBuilder())->fromFile()->build();

    // Construct a Google Ads client configured from a properties file and the
    // OAuth2 credentials above.
    $googleAdsClient = (new GoogleAdsClientBuilder())->fromFile()
      ->withOAuth2Credential($oAuth2Credential)
      ->build();

    try {
      $keywordPlanIdeaServiceClient = $googleAdsClient->getKeywordPlanIdeaServiceClient();

      // Make sure that keywords and/or page URL were specified. The request must have exactly one
      // of urlSeed, keywordSeed, or keywordAndUrlSeed set.
      if (empty($keywords) && is_null($pageUrl)) {
        throw new \InvalidArgumentException(
          'At least one of keywords or page URL is required, but neither was specified.'
        );
      }

      // Specify the optional arguments of the request as a keywordSeed, urlSeed,
      // or keywordAndUrlSeed.
      $requestOptionalArgs = [];
      if (empty($keywords)) {
        // Only page URL was specified, so use a UrlSeed.
        $requestOptionalArgs['urlSeed'] = new UrlSeed(['url' => $pageUrl]);
      } elseif (is_null($pageUrl)) {
        // Only keywords were specified, so use a KeywordSeed.
        $requestOptionalArgs['keywordSeed'] = new KeywordSeed(['keywords' => $keywords]);
      } else {
        // Both page URL and keywords were specified, so use a KeywordAndUrlSeed.
        $requestOptionalArgs['keywordAndUrlSeed'] =
          new KeywordAndUrlSeed(['url' => $pageUrl, 'keywords' => $keywords]);
      }

      // Create a list of geo target constants based on the resource name of specified location
      // IDs.
      $geoTargetConstants =  array_map(function ($locationId) {
        return ResourceNames::forGeoTargetConstant($locationId);
      }, $locationIds);

      // Generate keyword ideas based on the specified parameters.
      $response = $keywordPlanIdeaServiceClient->generateKeywordIdeas(
        [
          // Set the language resource using the provided language ID.
          'language' => ResourceNames::forLanguageConstant($languageId),
          'customerId' => $customerId,
          // Add the resource name of each location ID to the request.
          'geoTargetConstants' => $geoTargetConstants,
          // Set the network. To restrict to only Google Search, change the parameter below to
          // KeywordPlanNetwork::GOOGLE_SEARCH.
          'keywordPlanNetwork' => KeywordPlanNetwork::GOOGLE_SEARCH_AND_PARTNERS
        ] + $requestOptionalArgs
      );

      // Iterate over the results and print its detail.
      $search_volume = 0;
      foreach ($response->iterateAllElements() as $key => $result) {
        /** @var GenerateKeywordIdeaResult $result */
        // Note that the competition printed below is enum value.
        // For example, a value of 2 will be returned when the competition is 'LOW'.
        // A mapping of enum names to values can be found at KeywordPlanCompetitionLevel.php.
        if ($key == 0) {
          $search_volume = is_null($result->getKeywordIdeaMetrics()) ? 0 : $result->getKeywordIdeaMetrics()->getAvgMonthlySearches();
          $competition = is_null($result->getKeywordIdeaMetrics()) ? 0 : $result->getKeywordIdeaMetrics()->getCompetition();
        }
      }

      return $search_volume;
    } catch (GoogleAdsException $googleAdsException) {
      printf(
        "Request with ID '%s' has failed.%sGoogle Ads failure details:%s",
        $googleAdsException->getRequestId(),
        PHP_EOL,
        PHP_EOL
      );
      exit(1);
    } catch (ApiException $apiException) {
      printf(
        "ApiException was thrown with message '%s'.%s",
        $apiException->getMessage(),
        PHP_EOL
      );
      exit(1);
    }
  }

  public static function get_domain_rating(Request $request)
  {
    $domain = $request->input('domain');
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => "https://apiv2.ahrefs.com?from=domain_rating&target=" . $domain . "&mode=domain&output=json",
      CURLOPT_HTTPHEADER => array(
        "Content-Type: text/plain",
      ),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "GET"
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    $response = json_decode($response);
    if (!$response->error) {
      $res = $response->domain->domain_rating;
    }
    else {
      $res = 0;
    }

    return $res;
  }

  public static function get_domain_age(Request $request)
  {
    $domain = $request->input('domain');
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => "https://api.apilayer.com/whois/query?domain=" . $domain,
      CURLOPT_HTTPHEADER => array(
        "Content-Type: text/plain",
        "apikey: DJ2tb6OFqiFtodFPBdokyx39ODybOYpz"
      ),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "GET"
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    $response = json_decode($response);
    if ($response->result !== 'error') {
      $create_date = strtotime($response->result->creation_date);
      $current_date = $_SERVER['REQUEST_TIME'];

      $year_from = date('Y', $create_date);
      $year_to = date('Y', $current_date);

      $month_from = date('m', $create_date);
      $month_to = date('m', $current_date);

      $diff_months = (($year_to - $year_from) * 12) + ($month_to - $month_from);

      return $diff_months;
    }
    else {
      return 0;
    }
  }

  public static function get_instant_quote(Request $request)
  {
    $search_volume = $request->input('search_volume');
    $domain_age = $request->input('domain_age');
    $domain_rating = $request->input('domain_rating');

    // Init cost
    $cost = 0;

    switch ($search_volume) {
      case $search_volume < 100:
        $cost += 100;
        break;
      case $search_volume < 200:
        $cost += 150;
        break;
      case $search_volume < 500:
        $cost += 250;
        break;
      case $search_volume < 1000:
        $cost += 500;
        break;
      case $search_volume < 3000:
        $cost += 1000;
        break;
      case $search_volume < 10000:
        $cost += 1500;
        break;
      case $search_volume >= 10000:
        $cost += 2500;
        break;
      default:
        break;
    }

    switch ($domain_age) {
      case $domain_age < 3:
        $cost += 100;
        break;
      case $domain_age < 12:
        $cost += 50;
        break;
      case $domain_age < 24:
        $cost += 0;
        break;
      case $domain_age >= 24:
        $cost -= 25;
        break;
      default:
        break;
    }

    switch ($domain_rating) {
      case $domain_rating < 10:
        $cost += 100;
        break;
      case $domain_rating < 20:
        $cost += 50;
        break;
      case $domain_rating < 30:
        $cost += 0;
        break;
      case $domain_rating < 40:
        $cost -= 50;
        break;
      case $domain_rating >= 40:
        $cost -= 100;
        break;
      default:
        break;
    }

    // $data = ['message' => 'This is a test!'];
    // Mail::to('nico.developer.95@gmail.com')->send(new TestEmail($data));

    return $cost;
  }
}

// GenerateUserCredentials::main();
