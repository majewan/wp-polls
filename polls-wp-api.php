<?php

function poll_api_init() {
	global $wp_polls_api_poll;

	$wp_polls_api_poll = new WP_Polls_API_Poll();
	add_filter( 'json_endpoints', array( $wp_polls_api_poll, 'register_routes' ) );
}
add_action( 'wp_json_server_before_serve', 'poll_api_init' );

class WP_Polls_API_Poll {
	public function register_routes( $routes ) {
		$routes['/wp-polls/polls'] = array(
			array( array( $this, 'get_polls'), WP_JSON_Server::READABLE ),
			//array( array( $this, 'new_post'), WP_JSON_Server::CREATABLE | WP_JSON_Server::ACCEPT_JSON ),
    );

	  $routes['/wp-polls/polls/(?P<id>\d+)'] = array(
			array( array( $this, 'new_vote'), WP_JSON_Server::CREATABLE | WP_JSON_Server::ACCEPT_JSON ),
			array( array( $this, 'get_poll'), WP_JSON_Server::READABLE ),
			//array( array( $this, 'delete_post'), WP_JSON_Server::DELETABLE ),
		);

		return $routes;
	}

  public function get_polls(){
    global $wpdb;
    $polls = $wpdb->get_results("SELECT pollq_id FROM $wpdb->pollsq  ORDER BY pollq_timestamp DESC");

    $struct = array();
    foreach($polls as $poll){
      $struct[] = $this->get_poll($poll->pollq_id);
    }
    return $struct;
  }

  public function get_poll($id){
    do_action('wp_polls_display_pollresult');
    global $wpdb, $user_ID;
    $poll_id = intval($id);

    // Most/Least Variables
    $poll_most_answer = '';
    $poll_most_votes = 0;
    $poll_most_percentage = 0;
    $poll_least_answer = '';
    $poll_least_votes = 0;
    $poll_least_percentage = 0;
    // Get Poll Question Data
    $poll_question = $wpdb->get_row("SELECT pollq_id, pollq_question, pollq_totalvotes, pollq_active, pollq_timestamp, pollq_expiry, pollq_multiple, pollq_totalvoters FROM $wpdb->pollsq WHERE pollq_id = $poll_id LIMIT 1");
    // No poll could be loaded from the database
    if (!$poll_question) {
      return new WP_Error( 'json_poll_invalid', sprintf(__('Invalid Poll ID. Poll ID #%s', 'wp-polls'), $poll_id));
    }
    // Poll Question Variables
    $poll_data = array();
    $poll_data['ID'] = intval($poll_question->pollq_id);
    $poll_data['question_content'] = stripslashes($poll_question->pollq_question);
    $poll_data['totalvotes'] = intval($poll_question->pollq_totalvotes);
    $poll_data['totalvoters'] = intval($poll_question->pollq_totalvoters);
    $poll_data['active'] = (bool) intval($poll_question->pollq_active);
    $poll_data['start_date'] = gmdate('Y-m-d H:i:s', $poll_question->pollq_timestamp);
    $poll_expiry = trim($poll_question->pollq_expiry);
    if(empty($poll_expiry)) {
      $poll_end_date  = __('No Expiry', 'wp-polls');
    } else {
      $poll_data['end_date'] = gmdate('Y-m-d H:i:s', $poll_expiry);
    }
    $poll_data['multiple_ans'] = (bool) intval($poll_question->pollq_multiple);

    $user_answers = array();
    if($user_ID > 0){
      $user_answers = $wpdb->get_col("SELECT pollip_aid FROM $wpdb->pollsip WHERE pollip_userid = $user_ID AND pollip_qid = $poll_id");
    }
    // Get Poll Answers Data
    $poll_answers = $wpdb->get_results("SELECT polla_aid, polla_answers, polla_votes FROM $wpdb->pollsa WHERE polla_qid = $poll_id ORDER BY ".get_option('poll_ans_result_sortby').' '.get_option('poll_ans_result_sortorder'));
    // Store The Percentage Of The Poll
    $poll_answer_percentage_array = array();
    // Is The Poll Total Votes 0?
    $poll_totalvotes_zero = true;
    if($poll_data['totalvotes'] > 0) {
      $poll_totalvotes_zero = false;
    }
    $poll_data['answers'] = array();
    // Print Out Result Header Template
    foreach($poll_answers as $poll_answer) {
      // Poll Answer Variables
      $poll_data_answer = array();
      $poll_data_answer['ID'] = intval($poll_answer->polla_aid);
      $poll_data_answer['answer_content'] = stripslashes($poll_answer->polla_answers);
      $poll_data_answer['votes'] = intval($poll_answer->polla_votes);
      $poll_data_answer['percentage'] = 0;
      $poll_data_answer['imagewidth'] = 0;
      // Calculate Percentage And Image Bar Width
      if(!$poll_totalvotes_zero) {
        if($poll_data_answer['votes'] > 0) {
          $poll_data_answer['percentage'] = round((($poll_data_answer['votes']/$poll_data['totalvoters'])*100));
          $poll_answer_imagewidth = round($poll_data_answer['percentage']);
          if($poll_answer_imagewidth == 100) {
            $poll_answer_imagewidth = 99;
          }
        } else {
          $poll_data_answer['percentage'] = 0;
          $poll_answer_imagewidth = 1;
        }
      } else {
        $poll_data_answer['percentage'] = 0;
        $poll_answer_imagewidth = 1;
      }
      // Make Sure That Total Percentage Is 100% By Adding A Buffer To The Last Poll Answer
      $round_percentage = apply_filters( 'wp_polls_round_percentage', false );
      if( $round_percentage ) {
        if ( $poll_multiple_ans === 0 ) {
          $poll_answer_percentage_array[] = $poll_data_answer['percentage'];
          if ( sizeof( $poll_answer_percentage_array ) === sizeof( $poll_answers ) ) {
            $percentage_error_buffer = 100 - array_sum( $poll_answer_percentage_array );
            $poll_data_answer['percentage'] = $poll_data_answer['percentage'] + $percentage_error_buffer;
            if ( $poll_data_answer['percentage'] < 0 ) {
              $poll_data_answer['percentage'] = 0;
            }
          }
        }
      }

      if(in_array($poll_data_answer['ID'], $user_answers)){
        $poll_data_answer['vote_this'] = true;
      }
      $poll_data['answers'][] = $poll_data_answer;
    }
    // Return Poll Result
    return $poll_data;

  }

	/**
	 * Vote on poll
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Poll ID to vote
	 * @param array $data { 'poll_answers' => [0,3,6] }
	 * @return poll updated on success
	 */
  public function new_vote($id, $data){
    global $wpdb, $user_identity, $user_ID;
    $poll_id = $id;
    $poll_aid_array = $data['poll_answers'];
    if(!is_array($poll_aid_array)) $poll_aid_array = array($data['poll_answers']);

    if($poll_id > 0 && !empty($poll_aid_array) && check_allowtovote()) {
      $is_poll_open = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->pollsq WHERE pollq_id = %d AND pollq_active = 1", $poll_id ) ) );
      if ( $is_poll_open > 0 ) {
        $check_voted = check_voted($poll_id);
        if ($check_voted == 0) {
          if (!empty($user_identity)) {
            $pollip_user = htmlspecialchars(addslashes($user_identity));
          } elseif (!empty($_COOKIE['comment_author_' . COOKIEHASH])) {
            $pollip_user = htmlspecialchars(addslashes($_COOKIE['comment_author_' . COOKIEHASH]));
          } else {
            $pollip_user = __('Guest', 'wp-polls');
          }
          $pollip_userid = intval($user_ID);
          $pollip_ip = get_ipaddress();
          $pollip_host = esc_attr(@gethostbyaddr($pollip_ip));
          $pollip_timestamp = current_time('timestamp');
          // Only Create Cookie If User Choose Logging Method 1 Or 2
          $poll_logging_method = intval(get_option('poll_logging_method'));
          if ($poll_logging_method == 1 || $poll_logging_method == 3) {
            $cookie_expiry = intval(get_option('poll_cookielog_expiry'));
            if ($cookie_expiry == 0) {
              $cookie_expiry = 30000000;
            }
            $vote_cookie = setcookie('voted_' . $poll_id, $poll_aid, ($pollip_timestamp + $cookie_expiry), apply_filters('wp_polls_cookiepath', SITECOOKIEPATH));
          }
          $i = 0;
          foreach ($poll_aid_array as $polla_aid) {
            $update_polla_votes = $wpdb->query("UPDATE $wpdb->pollsa SET polla_votes = (polla_votes+1) WHERE polla_qid = $poll_id AND polla_aid = $polla_aid");
            if (!$update_polla_votes) {
              unset($poll_aid_array[$i]);
            }
            $i++;
          }
          $vote_q = $wpdb->query("UPDATE $wpdb->pollsq SET pollq_totalvotes = (pollq_totalvotes+" . sizeof($poll_aid_array) . "), pollq_totalvoters = (pollq_totalvoters+1) WHERE pollq_id = $poll_id AND pollq_active = 1");
          if ($vote_q) {
            foreach ($poll_aid_array as $polla_aid) {
              $wpdb->query("INSERT INTO $wpdb->pollsip VALUES (0, $poll_id, $polla_aid, '$pollip_ip', '$pollip_host', '$pollip_timestamp', '$pollip_user', $pollip_userid)");
            }
            //echo display_pollresult($poll_id, $poll_aid_array, false);
            return array( 'success' => true);
          } else {
            return new WP_Error( 'json_poll_total_error', sprintf(__('Unable To Update Poll Total Votes And Poll Total Voters. Poll ID #%s', 'wp-polls'), $poll_id));
          } // End if($vote_a)
        } else {
          return new WP_Error( 'json_poll_already_vote', sprintf(__('You Had Already Voted For This Poll. Poll ID #%s', 'wp-polls'), $poll_id));
        } // End if($check_voted)
      } else {
        return new WP_Error( 'json_poll_closed', sprintf( __( 'Poll ID #%s is closed', 'wp-polls' ), $poll_id ));
      }  // End if($is_poll_open > 0)
    } else {
      return new WP_Error( 'json_poll_invalid', sprintf(__('Invalid Poll ID. Poll ID #%s', 'wp-polls'), $poll_id));
    } // End if($poll_id > 0 && !empty($poll_aid_array) && check_allowtovote())
  }
}
