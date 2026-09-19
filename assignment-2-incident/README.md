# Assignment 2 — Production Incident

This folder contains the deliverables for Assignment 2 of the BookMyCharters technical assignment.

## What is Assignment 2?

Assignment 2 is a production incident reasoning exercise. The scenario involves a Yii2 application running on an AWS EC2 instance behind Nginx. The application is experiencing intermittent CPU and memory spikes due to invalid traffic (approximately 100 requests/minute) reaching the PHP framework. The goal is to design an immediate mitigation and a durable fix within specific constraints (two engineers, one week, minimal AWS spend) without breaking legitimate traffic, webhooks, or monitoring.

## Contents

- **`PROPOSAL.md`**: The technical proposal detailing the incident analysis, immediate mitigation, durable fix, trade-offs, and engineering reasoning.
- **`access-log-sample.log`**: The provided Nginx access log extract used for the analysis.

## How the Proposal Maps to the Requirements

- **Incident Analysis**: Classifies traffic into hostile, benign-but-noisy, and legitimate, providing evidence from the logs and explaining why naive blocking is dangerous.
- **Immediate Mitigation (Next Two Hours)**: Proposes a safe, Nginx-level blocklist for known hostile paths to reduce Yii2 processing overhead immediately, protecting critical endpoints.
- **Durable Fix (This Week)**: Proposes a maintainable Nginx file extension filtering and targeted rate-limiting strategy that fits the two-engineer/one-week constraint and minimizes AWS spend.
- **Weakest Point**: Identifies the risks of attackers using extensionless URLs and provides mitigation strategies.
- **Engineering Reasoning**: Explains the rationale behind the decisions, focusing on early rejection, protecting legitimate traffic, and balancing cost with operational safety.
