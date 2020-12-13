USER_ID?=$(shell id -u)
GROUP_ID?=$(shell id -g)

build-first-order-model:
	docker build -t first-order-model first-order-model
