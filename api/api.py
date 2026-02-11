from flask import Flask, request, jsonify
from google.ads.googleads.client import GoogleAdsClient
from google.ads.googleads.errors import GoogleAdsException
from google.protobuf import field_mask_pb2

app = Flask(__name__)

# -----------------------
# API KEY
API_KEY = "set your api key"
# -----------------------

# 🔥 فقط یک بار Client بسازیم (اصلاح اصلی)
client = GoogleAdsClient.load_from_storage("google-ads.yaml")


def update_campaign_status(customer_id, campaign_id, new_status):
    campaign_service = client.get_service("CampaignService")

    operation = client.get_type("CampaignOperation")
    campaign = operation.update

    campaign.resource_name = campaign_service.campaign_path(
        customer_id=customer_id,
        campaign_id=campaign_id
    )

    campaign.status = new_status

    mask = field_mask_pb2.FieldMask(paths=["status"])
    operation.update_mask.CopyFrom(mask)

    response = campaign_service.mutate_campaigns(
        customer_id=customer_id,
        operations=[operation]
    )
    return response.results[0].resource_name


@app.route("/pause", methods=["GET"])
def pause_campaign():
    key = request.args.get("key")
    if key != API_KEY:
        return jsonify({"status": "error", "message": "invalid api key"}), 403

    customer_id = request.args.get("customer_id")
    campaign_id = request.args.get("campaign_id")

    if not customer_id or not campaign_id:
        return jsonify({"status": "error", "message": "customer_id و campaign_id لازم است"}), 400

    try:
        resource = update_campaign_status(
            customer_id,
            campaign_id,
            new_status=client.enums.CampaignStatusEnum.PAUSED
        )

        return jsonify({
            "status": "success",
            "action": "paused",
            "resource": resource
        })

    except GoogleAdsException as ex:
        return jsonify({
            "status": "error",
            "errors": [e.message for e in ex.failure.errors]
        }), 400


@app.route("/enable", methods=["GET"])
def enable_campaign():
    key = request.args.get("key")
    if key != API_KEY:
        return jsonify({"status": "error", "message": "invalid api key"}), 403

    customer_id = request.args.get("customer_id")
    campaign_id = request.args.get("campaign_id")

    if not customer_id or not campaign_id:
        return jsonify({"status": "error", "message": "customer_id و campaign_id لازم است"}), 400

    try:
        resource = update_campaign_status(
            customer_id,
            campaign_id,
            new_status=client.enums.CampaignStatusEnum.ENABLED
        )

        return jsonify({
            "status": "success",
            "action": "enabled",
            "resource": resource
        })

    except GoogleAdsException as ex:
        return jsonify({
            "status": "error",
            "errors": [e.message for e in ex.failure.errors]
        }), 400


# -------------------------------------------------------
# 🔥 NEW → لیست کمپین‌ها
# -------------------------------------------------------
@app.route("/campaigns", methods=["GET"])
def list_campaigns():
    key = request.args.get("key")
    if key != API_KEY:
        return jsonify({"error": "invalid api key"}), 403

    customer_id = request.args.get("customer_id")
    if not customer_id:
        return jsonify({"error": "customer_id لازم است"}), 400

    try:
        ga_service = client.get_service("GoogleAdsService")

        query = """
        SELECT
            campaign.id,
            campaign.name,
            campaign.status
        FROM campaign
        ORDER BY campaign.id
        """

        response = ga_service.search(customer_id=customer_id, query=query)

        campaigns = []
        for row in response:
            campaigns.append({
                "id": row.campaign.id,
                "name": row.campaign.name,
                "status": row.campaign.status.name
            })

        return jsonify({"campaigns": campaigns})

    except Exception as e:
        return jsonify({"error": str(e)}), 500


# -------------------------------------------------------

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)
