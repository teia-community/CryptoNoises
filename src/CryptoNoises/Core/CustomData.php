<?php

namespace CryptoNoises\Core;

class CustomData
{
    const kAUCTION_ID               = 'auction_id';
    const kAUCTION_TOP_BID          = 'top_bid';
    const kAUCTION_TOP_OWNER        = 'top_owner';

    const kAUCTION_DIRECT_PRICE     = 'auction_direct_price';
    const kAUCTION_BID_INC_PCT      = 'auction_bid_inc_pct';

    const kDURATION                 = 'duration';                   // in seconds
    const kEXPIRED                  = 'expired';
    const kROYALTIES                = 'royalties';

    const kBURN_ON_END              = 'burn_on_end';

    const kITERATION_IDX            = 'iter_idx';
    const kITERATION_MARKET_URL_ID  = 'iter_market_url_id';
    const kITERATION_TOKEN_ID       = 'iter_token_id';

    const kDOMAIN                   = 'domain';
    const kDOMAIN_RENEW_URL         = 'domain_renew_url';

    // Account operations (delegation, staking, baker parameters, origination)
    const kBAKER_NAME               = 'baker_name';                 // TzKT alias as of the operation
    const kPREV_BAKER               = 'prev_baker';                 // address
    const kPREV_BAKER_NAME          = 'prev_baker_name';
    const kOPERATED_BY              = 'operated_by';                // address, when someone else sent it (e.g. a finalizing service)
    const kOPERATED_BY_NAME         = 'operated_by_name';
    const kUNLOCK_TIME              = 'unlock_time';                // ISO time the unstaked tez can be finalized from
    const kUNLOCK_CYCLE             = 'unlock_cycle';
    const kEDGE                     = 'edge';                       // billionths of the stakers' rewards the baker keeps
    const kEDGE_PREV                = 'edge_prev';
    const kLIMIT                    = 'limit';                      // millionths: staking capacity as a multiple of the baker's own stake
    const kLIMIT_PREV               = 'limit_prev';
    const kACTIVATION_CYCLE         = 'activation_cycle';
    const kACTIVATION_TIME          = 'activation_time';            // ISO, estimated start of the activation cycle
    const kCONTRACT                 = 'contract';                   // originated contract
}
